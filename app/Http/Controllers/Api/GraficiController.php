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
}
