<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
}
