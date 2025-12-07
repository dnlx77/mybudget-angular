<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Operazione;
use App\Models\Conto;
use Carbon\Carbon;

class GraficiController extends Controller
{
    /**
     * Spese aggregate per tag con filtri personalizzabili
     * Mostra top 10 categorie + raggruppa il resto in "Altri"
     * 
     * Query parameters:
     * - data_inizio: Data di inizio periodo (formato: Y-m-d, default: 30 giorni fa)
     * - data_fine: Data di fine periodo (formato: Y-m-d, default: oggi)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function spesePerTag(Request $request)
    {
        try {
            // ============================================================
            // PARSING FILTRI CON DEFAULT
            // ============================================================

            $dataInizio = $request->input('data_inizio')
                ? Carbon::parse($request->input('data_inizio'))->startOfDay()
                : Carbon::now()->subDays(30)->startOfDay();

            $dataFine = $request->input('data_fine')
                ? Carbon::parse($request->input('data_fine'))->endOfDay()
                : Carbon::now()->endOfDay();

            // ⬇️ GESTIONE ROBUSTA DI conto_id
            $contoIdRaw = $request->input('conto_id');

            // Normalizza: converti 'undefined', 'null', '', 0 a null
            $contoId = null;
            if (
                $contoIdRaw !== null &&
                $contoIdRaw !== '' &&
                $contoIdRaw !== 'undefined' &&
                $contoIdRaw !== 'null'
            ) {
                $contoId = (int) $contoIdRaw;
                // Se dopo la conversione è 0, trattalo come null
                if ($contoId === 0) {
                    $contoId = null;
                }
            }

            // ============================================================
            // 1. CALCOLA TOTALE GENERALE
            // ============================================================

            $queryTotale = DB::table('operazioni')
                ->where('importo', '<', 0)
                ->whereBetween('data_operazione', [$dataInizio, $dataFine]);

            // ⬇️ LOGICA CONDIZIONALE: Escludi trasferimenti solo se NON c'è filtro conto
            if (!$contoId) {
                $queryTotale->where('trasferimento', 'N');  // Escludi trasferimenti (visione globale)
            } else {
                $queryTotale->where('conto_id', $contoId);  // Filtra per conto specifico
            }

            $totaleGenerale = $queryTotale->sum(DB::raw('ABS(importo)'));
            $totaleGenerale = $totaleGenerale ?? 0;

            // ============================================================
            // 2. QUERY SPESE PER TAG
            // ============================================================

            $querySpese = DB::table('operazioni')
                ->join('rel_operazioni_tags', 'operazioni.id', '=', 'rel_operazioni_tags.operazione_id')
                ->join('tags', 'rel_operazioni_tags.tag_id', '=', 'tags.id')
                ->where('operazioni.importo', '<', 0)
                ->whereBetween('operazioni.data_operazione', [$dataInizio, $dataFine]);

            // ⬇️ LOGICA CONDIZIONALE: Escludi trasferimenti solo se NON c'è filtro conto
            if (!$contoId) {
                $querySpese->where('operazioni.trasferimento', 'N');  // Escludi trasferimenti (visione globale)
            } else {
                $querySpese->where('operazioni.conto_id', $contoId);  // Filtra per conto specifico
            }

            $tutteLeSpesePerTag = $querySpese
                ->select(
                    'tags.nome',
                    'tags.id',
                    DB::raw('SUM(ABS(operazioni.importo)) as totale'),
                    DB::raw('COUNT(DISTINCT operazioni.id) as num_operazioni')
                )
                ->groupBy('tags.id', 'tags.nome')
                ->orderBy('totale', 'DESC')
                ->get();

            // ============================================================
            // 3. RAGGRUPPA TOP 10 + ALTRI
            // ============================================================

            $top10 = $tutteLeSpesePerTag->take(10);
            $altri = $tutteLeSpesePerTag->skip(10);
            $spese = $top10->values()->toArray();

            if ($altri->count() > 0) {
                $spese[] = [
                    'id' => 0,
                    'nome' => 'Altri',
                    'totale' => $altri->sum('totale'),
                    'num_operazioni' => $altri->sum('num_operazioni')
                ];
            }

            // ============================================================
            // 4. RISPOSTA JSON
            // ============================================================

            return response()->json([
                'success' => true,
                'data' => $spese,
                'filtri' => [
                    'data_inizio' => $dataInizio->format('Y-m-d'),
                    'data_fine' => $dataFine->format('Y-m-d'),
                    'giorni' => (int) $dataInizio->diffInDays($dataFine)
                ],
                'totale_generale' => $totaleGenerale,  // ⬅️ Totale CORRETTO (senza duplicati)
                'totale_distribuito' => $tutteLeSpesePerTag->sum('totale'),  // ⬅️ Totale della distribuzione (può essere > del reale)
                'num_categorie_totali' => $tutteLeSpesePerTag->count(),
                'num_categorie_mostrate' => count($spese)
            ]);
        } catch (\Exception $e) {
            logger()->error('Errore in spesePerTag', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * GET /api/v1/grafici/guadagni-spese
     * 
     * Confronto mensile: guadagni (entrate) vs spese (uscite)
     * 
     * Query parameters:
     * - data_inizio: 2025-01-01
     * - data_fine: 2025-12-31
     * - conto_id: (opzionale) filtra per conto
     * - tags: id1,id2,id3 (opzionale) filtra per tag
     */
    public function guadagniVsSpese(Request $request)
    {
        try {
            // ============================================================
            // PARSING FILTRI
            // ============================================================
            $dataInizio = $request->input('data_inizio')
                ? Carbon::parse($request->input('data_inizio'))->startOfDay()
                : Carbon::now()->subMonths(12)->startOfDay();

            $dataFine = $request->input('data_fine')
                ? Carbon::parse($request->input('data_fine'))->endOfDay()
                : Carbon::now()->endOfDay();

            // Normalizza conto_id
            $contoIdRaw = $request->input('conto_id');
            $contoId = null;
            if (
                $contoIdRaw !== null &&
                $contoIdRaw !== '' &&
                $contoIdRaw !== 'undefined' &&
                $contoIdRaw !== 'null'
            ) {
                $contoId = (int) $contoIdRaw;
                if ($contoId === 0) {
                    $contoId = null;
                }
            }

            // ============================================================
            // QUERY: Guadagni e Spese per mese
            // ============================================================
            $query = Operazione::selectRaw('
            DATE_FORMAT(data_operazione, "%Y-%m") as mese,
            COALESCE(SUM(CASE WHEN importo > 0 THEN importo ELSE 0 END), 0) as guadagni,
            COALESCE(SUM(CASE WHEN importo < 0 THEN ABS(importo) ELSE 0 END), 0) as spese
        ')
                //->where('trasferimento', 'N')  // ⬅️ SEMPRE escludi trasferimenti
                ->whereBetween('data_operazione', [$dataInizio, $dataFine]);

            // ⭐ LOGICA CONDIZIONALE: Trasferimenti dipendono dal filtro conto
            if (!$contoId) {
                // Visione globale: escludi trasferimenti per evitare duplicati
                $query->where('trasferimento', 'N');
            }
            // Se conto_id è presente: INCLUDI i trasferimenti (non aggiungo WHERE)

            // Filtra per conto se specificato
            if ($contoId) {
                $query->where('conto_id', $contoId);
            }

            $dati = $query
                ->groupByRaw('DATE_FORMAT(data_operazione, "%Y-%m")')
                ->orderBy('mese', 'ASC')
                ->get();

            // ============================================================
            // TRASFORMAZIONE DATI
            // ============================================================
            $result = $dati->map(function ($row) {
                return [
                    'mese' => $row->mese,
                    'guadagni' => (float) $row->guadagni,
                    'spese' => (float) $row->spese,
                    'saldo_netto' => (float) ($row->guadagni - $row->spese)
                ];
            })->values()->toArray();

            // Calcola totali
            $totaleGuadagni = collect($result)->sum('guadagni');
            $totaleSpes = collect($result)->sum('spese');

            return response()->json([
                'success' => true,
                'data' => $result,
                'filtri' => [
                    'data_inizio' => $dataInizio->format('Y-m-d'),
                    'data_fine' => $dataFine->format('Y-m-d'),
                    'conto_id' => $contoId
                ],
                'statistiche' => [
                    'totale_guadagni' => $totaleGuadagni,
                    'totale_spese' => $totaleSpes,
                    'saldo_netto' => $totaleGuadagni - $totaleSpes,
                    'num_mesi' => count($result)
                ],
                'message' => 'Guadagni vs Spese recuperati'
            ]);
        } catch (\Exception $e) {
            logger()->error('Errore in guadagniVsSpese', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    /**
     * GET /api/v1/grafici/andamento-saldo
     * 
     * Andamento cumulativo del saldo nel tempo
     * 
     * Query parameters:
     * - data_inizio: 2025-01-01
     * - data_fine: 2025-12-31
     * - conto_id: (opzionale, obbligatorio per accuratezza saldo iniziale)
     */
    public function andamentoSaldo(Request $request)
    {
        try {
            // ============================================================
            // VALIDAZIONE: conto_id OBBLIGATORIO
            // ============================================================
            $contoIdRaw = $request->input('conto_id');
            $contoId = null;
            if (
                $contoIdRaw !== null &&
                $contoIdRaw !== '' &&
                $contoIdRaw !== 'undefined' &&
                $contoIdRaw !== 'null'
            ) {
                $contoId = (int) $contoIdRaw;
                if ($contoId === 0) {
                    $contoId = null;
                }
            }

            if (!$contoId) {
                return response()->json([
                    'success' => false,
                    'error' => 'conto_id è obbligatorio per visualizzare l\'andamento del saldo',
                    'code' => 400
                ], 400);
            }

            // ============================================================
            // PARSING FILTRI
            // ============================================================
            $dataInizio = $request->input('data_inizio')
                ? Carbon::parse($request->input('data_inizio'))->startOfDay()
                : Carbon::now()->subMonths(3)->startOfDay();

            $dataFine = $request->input('data_fine')
                ? Carbon::parse($request->input('data_fine'))->endOfDay()
                : Carbon::now()->endOfDay();

            // ============================================================
            // 1. RECUPERA IL CONTO E IL SALDO INIZIALE
            // ============================================================
            $conto = Conto::findOrFail($contoId);
            $saldoIniziale = (float) ($conto->saldo_iniziale ?? 0);

            // ============================================================
            // 2. RECUPERA OPERAZIONI DEL CONTO NEL PERIODO
            // ============================================================
            $operazioni = Operazione::where('conto_id', $contoId)
                ->whereBetween('data_operazione', [$dataInizio, $dataFine])
                ->orderBy('data_operazione', 'ASC')
                ->select('data_operazione', 'importo')
                ->get();

            // ============================================================
            // 3. CALCOLA SALDO CUMULATIVO GIORNO PER GIORNO
            // ============================================================
            $andamento = [];
            $saldoCorrente = $saldoIniziale;

            // Raggruppa operazioni per data
            $operazioniByDate = $operazioni->groupBy('data_operazione');

            // Genera tutte le date da inizio a fine
            $currentDate = clone $dataInizio;

            while ($currentDate <= $dataFine) {
                $formattedDate = $currentDate->format('Y-m-d');

                // Se ci sono operazioni in questa data, aggiorna il saldo
                if (isset($operazioniByDate[$formattedDate])) {
                    $importoGiorno = $operazioniByDate[$formattedDate]->sum('importo');
                    $saldoCorrente += $importoGiorno;
                }

                $andamento[] = [
                    'data' => $formattedDate,
                    'saldo' => round($saldoCorrente, 2)
                ];

                $currentDate->addDay();
            }

            // ============================================================
            // 4. STATISTICHE
            // ============================================================
            $saldoMinimo = min(array_column($andamento, 'saldo'));
            $saldoMassimo = max(array_column($andamento, 'saldo'));

            return response()->json([
                'success' => true,
                'data' => $andamento,
                'conto' => [                    // ⬅️ AGGIUNTO!
                    'id' => $conto->id,
                    'nome' => $conto->nome
                ],
                'filtri' => [
                    'data_inizio' => $dataInizio->format('Y-m-d'),
                    'data_fine' => $dataFine->format('Y-m-d')
                ],
                'statistiche' => [              // ⬅️ AGGIUNTO!
                    'saldo_iniziale' => $saldoIniziale,
                    'saldo_finale' => round($saldoCorrente, 2),
                    'variazione' => round($saldoCorrente - $saldoIniziale, 2),
                    'saldo_minimo' => $saldoMinimo,
                    'saldo_massimo' => $saldoMassimo,
                    'num_giorni' => count($andamento)
                ],
                'message' => 'Andamento saldo recuperato'
            ]);
        } catch (\Exception $e) {
            logger()->error('Errore in andamentoSaldo', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}
