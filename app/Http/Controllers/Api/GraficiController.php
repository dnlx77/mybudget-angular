<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Operazione;
use App\Models\Conto;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GraficiController extends Controller
{
    /**
     * Helper privato per calcolare il periodo corretto.
     * LOGICA SMART: Se la data inizio è molto vecchia (< 2000), 
     * assume che l'utente voglia "Tutto" e cerca la prima operazione nel DB.
     */
    private function getPeriodo(Request $request)
    {
        $dataInizio = $request->input('data_inizio')
            ? Carbon::parse($request->input('data_inizio'))->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();

        $dataFine = $request->input('data_fine')
            ? Carbon::parse($request->input('data_fine'))->endOfDay()
            : Carbon::now()->endOfDay();

        // CLAMPING: Se l'anno è < 2000 (es. 1970), cerca la data minima reale
        if ($dataInizio->year < 2000) {
            $minDate = Operazione::min('data_operazione');
            if ($minDate) {
                $dataInizio = Carbon::parse($minDate)->startOfDay();
            }
        }

        return [$dataInizio, $dataFine];
    }

    private function applicaFiltri($query, $request)
    {
        $contoId = $request->input('conto_id');
        if ($contoId && $contoId !== 'null' && $contoId !== 'undefined') {
            $query->where('conto_id', $contoId);
        }

        $tagIds = $request->input('tag_ids');
        if (!empty($tagIds)) {
            if (is_string($tagIds)) $tagIds = explode(',', $tagIds);
            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            });
        }
    }

    public function spesePerTag(Request $request)
    {
        try {
            [$dataInizio, $dataFine] = $this->getPeriodo($request);

            $query = Operazione::with('tags')
                ->where('importo', '<', 0)
                ->where('trasferimento', 'N')
                ->whereBetween('data_operazione', [$dataInizio, $dataFine]);

            $this->applicaFiltri($query, $request);

            $operazioni = $query->get();
            $spesePerTag = [];
            $totaleGenerale = 0;

            $filterTagIds = $request->input('tag_ids');
            if (is_string($filterTagIds)) $filterTagIds = explode(',', $filterTagIds);

            foreach ($operazioni as $op) {
                $importo = abs($op->importo);
                $tagsDaContare = $op->tags;

                if (!empty($filterTagIds)) {
                    $tagsDaContare = $tagsDaContare->whereIn('id', $filterTagIds);
                }

                if ($tagsDaContare->isEmpty()) {
                    if (empty($filterTagIds)) {
                        $key = 'Nessun Tag';
                        if (!isset($spesePerTag[$key])) $spesePerTag[$key] = ['nome' => $key, 'totale' => 0, 'num_operazioni' => 0];
                        $spesePerTag[$key]['totale'] += $importo;
                        $spesePerTag[$key]['num_operazioni']++;
                        $totaleGenerale += $importo;
                    }
                } else {
                    $totaleGenerale += $importo;
                    foreach ($tagsDaContare as $tag) {
                        $key = $tag->nome;
                        if (!isset($spesePerTag[$key])) $spesePerTag[$key] = ['nome' => $key, 'totale' => 0, 'num_operazioni' => 0];
                        $spesePerTag[$key]['totale'] += $importo;
                        $spesePerTag[$key]['num_operazioni']++;
                    }
                }
            }

            usort($spesePerTag, fn($a, $b) => $b['totale'] <=> $a['totale']);

            $limit = 9;
            $chartData = [];
            $useOther = empty($filterTagIds) || count($filterTagIds) > $limit;

            if ($useOther && count($spesePerTag) > $limit) {
                $chartData = array_slice($spesePerTag, 0, $limit);
                $others = array_slice($spesePerTag, $limit);
                $totaleAltro = 0;
                $opsAltro = 0;
                foreach ($others as $item) {
                    $totaleAltro += $item['totale'];
                    $opsAltro += $item['num_operazioni'];
                }
                if ($totaleAltro > 0) {
                    $chartData[] = ['nome' => 'Altro', 'totale' => $totaleAltro, 'num_operazioni' => $opsAltro];
                }
            } else {
                $chartData = $spesePerTag;
            }

            return response()->json([
                'success' => true,
                'data' => $chartData,
                'totale_generale' => $totaleGenerale,
                'filtri' => [
                    'giorni' => $dataInizio->diffInDays($dataFine),
                    'inizio' => $dataInizio->format('Y-m-d'),
                    'fine' => $dataFine->format('Y-m-d')
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function guadagniVsSpese(Request $request)
    {
        try {
            [$dataInizio, $dataFine] = $this->getPeriodo($request);

            // 1. Determina Scala
            $diffGiorni = $dataInizio->diffInDays($dataFine);

            $groupByFormat = 'Y-m-d'; // Default: Giorno
            $labelFormat = 'd/m';
            $addMethod = 'addDay';
            $startMethod = null;

            if ($diffGiorni > 730) { // > 2 Anni -> ANNUALE
                $groupByFormat = 'Y';
                $labelFormat = 'Y';
                $addMethod = 'addYear';
                $startMethod = 'startOfYear';
            } elseif ($diffGiorni > 60) { // > 2 Mesi -> MENSILE
                $groupByFormat = 'Y-m';
                $labelFormat = 'M Y'; // es. Jan 2024
                $addMethod = 'addMonth';
                $startMethod = 'startOfMonth';
            }

            // 2. Query
            $query = Operazione::query()
                ->where('trasferimento', 'N')
                ->whereBetween('data_operazione', [$dataInizio, $dataFine]);

            $this->applicaFiltri($query, $request);

            $operazioniRaggruppate = $query->get()
                ->groupBy(function ($op) use ($groupByFormat) {
                    return Carbon::parse($op->data_operazione)->format($groupByFormat);
                });

            // 3. Generazione Dati (con riempimento buchi)
            $chartData = [];
            $totGuadagni = 0;
            $totSpese = 0;

            $cursore = clone $dataInizio;
            if ($startMethod) $cursore->$startMethod();

            while ($cursore->format($groupByFormat) <= $dataFine->format($groupByFormat)) {

                $chiave = $cursore->format($groupByFormat);
                $guadagniPeriodo = 0;
                $spesePeriodo = 0;

                if (isset($operazioniRaggruppate[$chiave])) {
                    $ops = $operazioniRaggruppate[$chiave];
                    $guadagniPeriodo = $ops->where('importo', '>', 0)->sum('importo');
                    $spesePeriodo = abs($ops->where('importo', '<', 0)->sum('importo'));
                }

                $totGuadagni += $guadagniPeriodo;
                $totSpese += $spesePeriodo;

                $chartData[] = [
                    'data' => $cursore->format($labelFormat), // "data" è l'etichetta asse X
                    'guadagni' => round($guadagniPeriodo, 2),
                    'spese' => round($spesePeriodo, 2)
                ];

                $cursore->$addMethod();
            }

            return response()->json([
                'success' => true,
                'data' => $chartData,
                'statistiche' => [
                    'totale_guadagni' => round($totGuadagni, 2),
                    'totale_spese' => round($totSpese, 2),
                    'saldo_netto' => round($totGuadagni - $totSpese, 2)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function andamentoSaldo(Request $request)
    {
        try {
            // 1. INPUT e Periodo (con logica "Tutto")
            [$dataInizio, $dataFine] = $this->getPeriodo($request);

            $contoId = $request->input('conto_id');
            if ($contoId === 'null' || $contoId === 'undefined' || $contoId === '') $contoId = null;

            // 2. DECIDIAMO LA SCALA (Giornaliera / Mensile / Annuale)
            // Ripristinata logica granulare
            $diffGiorni = $dataInizio->diffInDays($dataFine);

            $groupByFormat = 'Y-m-d'; // Default: Giornaliero
            $labelFormat = 'd/m';
            $addMethod = 'addDay';
            $startMethod = null;

            if ($diffGiorni > 730) { // > 2 Anni -> ANNUALE
                $groupByFormat = 'Y';
                $labelFormat = 'Y';
                $addMethod = 'addYear';
                $startMethod = 'startOfYear';
            } elseif ($diffGiorni > 90) { // > 3 Mesi -> MENSILE
                $groupByFormat = 'Y-m';
                $labelFormat = 'm/Y';
                $addMethod = 'addMonth';
                $startMethod = 'startOfMonth';
            }

            // 3. CALCOLO SALDO INIZIALE
            $saldoInizialePeriodo = 0;
            $tagIds = $request->input('tag_ids');
            $hasTagFilter = !empty($tagIds);

            if (!$hasTagFilter) {
                $queryStorico = Operazione::where('data_operazione', '<', $dataInizio);
                if ($contoId) $queryStorico->where('conto_id', $contoId);
                $saldoInizialePeriodo = (float) $queryStorico->sum('importo');
            }

            // 4. RECUPERO DATI PERIODO
            $queryOps = Operazione::whereBetween('data_operazione', [$dataInizio, $dataFine])
                ->orderBy('data_operazione', 'ASC');

            $this->applicaFiltri($queryOps, $request);

            // Raggruppa i movimenti secondo la scala scelta (usando il formato corretto)
            $operazioniRaggruppate = $queryOps->get()
                ->groupBy(function ($op) use ($groupByFormat) {
                    return Carbon::parse($op->data_operazione)->format($groupByFormat);
                });

            // 5. GENERAZIONE PUNTI
            $andamento = [];
            $saldoCorrente = $saldoInizialePeriodo;

            $cursore = clone $dataInizio;
            if ($startMethod) $cursore->$startMethod(); // Normalizza inizio (es. 1 Gennaio)

            // Loop fino alla data fine, usando il formato di raggruppamento per il confronto
            while ($cursore->format($groupByFormat) <= $dataFine->format($groupByFormat)) {

                $chiave = $cursore->format($groupByFormat); // es. "2024-01" o "2024"

                // Se ci sono operazioni in questo "bucket", aggiorna il saldo
                if (isset($operazioniRaggruppate[$chiave])) {
                    $saldoCorrente += $operazioniRaggruppate[$chiave]->sum('importo');
                }

                $andamento[] = [
                    'data' => $cursore->format($labelFormat),
                    'saldo' => round($saldoCorrente, 2),
                    'full_date' => $cursore->format('Y-m-d')
                ];

                $cursore->$addMethod();
            }

            // 6. RISPOSTA
            $saldi = array_column($andamento, 'saldo');
            $saldoMin = count($saldi) > 0 ? min($saldi) : $saldoInizialePeriodo;
            $saldoMax = count($saldi) > 0 ? max($saldi) : $saldoInizialePeriodo;

            $nomeConto = $contoId ? Conto::find($contoId)->nome : 'Patrimonio Totale';
            if ($hasTagFilter) $nomeConto .= ' (Filtrato)';

            return response()->json([
                'success' => true,
                'data' => $andamento,
                'conto' => ['id' => $contoId, 'nome' => $nomeConto],
                'statistiche' => [
                    'saldo_iniziale' => round($saldoInizialePeriodo, 2),
                    'saldo_finale' => round($saldoCorrente, 2),
                    'variazione' => round($saldoCorrente - $saldoInizialePeriodo, 2),
                    'saldo_minimo' => $saldoMin,
                    'saldo_massimo' => $saldoMax
                ]
            ]);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ============================================================
    // CONFRONTO TRA PERIODI
    // ============================================================

    /**
     * GET /api/v1/grafici/confronto-periodi
     *
     * Confronta il periodo corrente (A) con uno precedente (B), scelto da un preset.
     * Con parita_giorni=1 (default) e periodi in corso, B viene limitato agli stessi
     * giorni trascorsi di A (es. 1-20 settembre vs 1-20 agosto), altrimenti si
     * confrontano i periodi interi. Trasferimenti esclusi, come negli altri grafici.
     */
    public function confrontoPeriodi(Request $request)
    {
        try {
            $request->validate([
                'preset' => 'nullable|in:mese_scorso,anno_scorso,stesso_mese_anno_scorso',
                'parita_giorni' => 'nullable|boolean',
            ]);

            $preset = $request->input('preset', 'mese_scorso');
            $parita = $request->boolean('parita_giorni', true);

            [$inizioA, $fineA, $inizioB, $fineB, $oggi] = $this->calcolaPeriodiConfronto($preset, $parita);

            $aggA = $this->aggregaPeriodo($request, $inizioA, $fineA);
            $aggB = $this->aggregaPeriodo($request, $inizioB, $fineB);

            $punti = max($this->giorniInclusi($inizioA, $fineA), $this->giorniInclusi($inizioB, $fineB));

            // A si ferma a oggi (i giorni futuri non hanno ancora dati)
            $finoAOggi = $oggi->copy()->endOfDay();
            $limiteA = $fineA->lessThan($finoAOggi) ? $fineA : $finoAOggi;

            $etichette = [];
            for ($i = 0; $i < $punti; $i++) {
                $etichette[] = $preset === 'anno_scorso'
                    ? $inizioA->copy()->addDays($i)->format('d/m')
                    : (string) ($i + 1);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'preset' => $preset,
                    'parita_giorni' => $parita,
                    'periodo_a' => $this->descriviPeriodo($inizioA, $fineA, $preset),
                    'periodo_b' => $this->descriviPeriodo($inizioB, $fineB, $preset),
                    'riepilogo' => [
                        'entrate' => $this->variazione($aggA['entrate'], $aggB['entrate']),
                        'uscite' => $this->variazione($aggA['uscite'], $aggB['uscite']),
                        'saldo' => $this->variazione(
                            $aggA['entrate'] - $aggA['uscite'],
                            $aggB['entrate'] - $aggB['uscite']
                        ),
                    ],
                    'per_tag' => $this->unisciTagPeriodi($aggA['per_tag'], $aggB['per_tag']),
                    'cumulativa' => [
                        'etichette' => $etichette,
                        'a' => $this->serieCumulativa($inizioA, $limiteA, $aggA['uscite_giornaliere'], $punti),
                        'b' => $this->serieCumulativa($inizioB, $fineB, $aggB['uscite_giornaliere'], $punti),
                    ],
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calcola gli estremi di A (periodo corrente) e B (periodo di confronto).
     * Con la parità, B è A traslato indietro di un mese/anno (senza overflow,
     * es. 31 marzo -> 28 febbraio) e A si ferma a oggi.
     */
    private function calcolaPeriodiConfronto(string $preset, bool $parita): array
    {
        $oggi = Carbon::now();

        if ($preset === 'anno_scorso') {
            $inizioA = $oggi->copy()->startOfYear();
            $inizioB = $inizioA->copy()->subYear();
            $fineNaturaleA = $inizioA->copy()->endOfYear();
            $fineNaturaleB = $inizioB->copy()->endOfYear();
            $fineParitaB = $oggi->copy()->subYearNoOverflow();
        } else {
            $inizioA = $oggi->copy()->startOfMonth();
            $spostaIndietro = fn (Carbon $d) => $preset === 'mese_scorso'
                ? $d->copy()->subMonthNoOverflow()
                : $d->copy()->subYearNoOverflow();
            $inizioB = $spostaIndietro($inizioA);
            $fineNaturaleA = $inizioA->copy()->endOfMonth();
            $fineNaturaleB = $inizioB->copy()->endOfMonth();
            $fineParitaB = $spostaIndietro($oggi);
        }

        $fineA = $parita ? $oggi->copy()->endOfDay() : $fineNaturaleA;
        $fineB = $parita ? $fineParitaB->endOfDay() : $fineNaturaleB;

        return [$inizioA, $fineA, $inizioB, $fineB, $oggi];
    }

    private function giorniInclusi(Carbon $inizio, Carbon $fine): int
    {
        return (int) round($inizio->copy()->startOfDay()->diffInDays($fine->copy()->startOfDay())) + 1;
    }

    private function descriviPeriodo(Carbon $inizio, Carbon $fine, string $preset): array
    {
        $etichetta = $preset === 'anno_scorso'
            ? $inizio->format('Y')
            : ucfirst($inizio->copy()->locale('it')->translatedFormat('F Y'));

        return [
            'etichetta' => $etichetta,
            'inizio' => $inizio->format('Y-m-d'),
            'fine' => $fine->format('Y-m-d'),
        ];
    }

    /**
     * Entrate, uscite, spese per tag e uscite giornaliere di un periodo.
     * Per le spese per tag vale la stessa regola del grafico a torta: un'operazione
     * con più tag conta per intero su ciascuno.
     */
    private function aggregaPeriodo(Request $request, Carbon $inizio, Carbon $fine): array
    {
        $query = Operazione::with('tags')
            ->where('trasferimento', 'N')
            ->whereBetween('data_operazione', [$inizio, $fine]);

        $this->applicaFiltri($query, $request);

        $filterTagIds = $request->input('tag_ids');
        if (is_string($filterTagIds)) $filterTagIds = explode(',', $filterTagIds);

        $entrate = 0.0;
        $uscite = 0.0;
        $perTag = [];
        $usciteGiornaliere = [];

        foreach ($query->get() as $op) {
            $importo = (float) $op->importo;

            if ($importo > 0) {
                $entrate += $importo;
                continue;
            }
            if ($importo == 0) continue;

            $spesa = abs($importo);
            $uscite += $spesa;

            $giorno = Carbon::parse($op->data_operazione)->format('Y-m-d');
            $usciteGiornaliere[$giorno] = ($usciteGiornaliere[$giorno] ?? 0) + $spesa;

            $tags = $op->tags;
            if (!empty($filterTagIds)) {
                $tags = $tags->whereIn('id', $filterTagIds);
            }

            if ($tags->isEmpty()) {
                if (empty($filterTagIds)) {
                    $perTag[0] = ['nome' => 'Nessun Tag', 'totale' => ($perTag[0]['totale'] ?? 0) + $spesa];
                }
                continue;
            }

            foreach ($tags as $tag) {
                $perTag[$tag->id] = ['nome' => $tag->nome, 'totale' => ($perTag[$tag->id]['totale'] ?? 0) + $spesa];
            }
        }

        return [
            'entrate' => $entrate,
            'uscite' => $uscite,
            'per_tag' => $perTag,
            'uscite_giornaliere' => $usciteGiornaliere,
        ];
    }

    /**
     * Unisce le spese per tag di A e B PRIMA di troncare ai primi N,
     * così "Altro" è confrontabile tra i due periodi.
     */
    private function unisciTagPeriodi(array $tagA, array $tagB, int $limite = 10): array
    {
        $unione = [];

        foreach ($tagA as $id => $t) {
            $unione[$id] = ['nome' => $t['nome'], 'a' => $t['totale'], 'b' => 0.0];
        }
        foreach ($tagB as $id => $t) {
            $unione[$id] ??= ['nome' => $t['nome'], 'a' => 0.0, 'b' => 0.0];
            $unione[$id]['b'] = $t['totale'];
        }

        $righe = array_values($unione);
        usort($righe, fn ($x, $y) => ($y['a'] + $y['b']) <=> ($x['a'] + $x['b']));

        if (count($righe) > $limite) {
            $resto = array_slice($righe, $limite);
            $righe = array_slice($righe, 0, $limite);
            $righe[] = [
                'nome' => 'Altro',
                'a' => array_sum(array_column($resto, 'a')),
                'b' => array_sum(array_column($resto, 'b')),
            ];
        }

        return array_map(fn ($r) => [
            'nome' => $r['nome'],
            'a' => round($r['a'], 2),
            'b' => round($r['b'], 2),
        ], $righe);
    }

    /**
     * Spesa cumulata giorno per giorno; null oltre $fineEffettiva (la linea si interrompe).
     */
    private function serieCumulativa(Carbon $inizio, Carbon $fineEffettiva, array $usciteGiornaliere, int $punti): array
    {
        $serie = [];
        $cumulo = 0.0;

        for ($i = 0; $i < $punti; $i++) {
            $giorno = $inizio->copy()->startOfDay()->addDays($i);

            if ($giorno->greaterThan($fineEffettiva)) {
                $serie[] = null;
                continue;
            }

            $cumulo += $usciteGiornaliere[$giorno->format('Y-m-d')] ?? 0;
            $serie[] = round($cumulo, 2);
        }

        return $serie;
    }

    private function variazione(float $a, float $b): array
    {
        return [
            'a' => round($a, 2),
            'b' => round($b, 2),
            'differenza' => round($a - $b, 2),
            'percentuale' => abs($b) > 0.005 ? round(($a - $b) / abs($b) * 100, 1) : null,
        ];
    }
}
