<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PagamentoRata;
use Illuminate\Http\Request;

/**
 * PagamentiRateApiController - API REST per gestione pagamenti a rate
 *
 * Un pagamento a rate è un "contenitore" (nome, importo totale, numero rate):
 * il residuo, le rate pagate e lo stato si calcolano sempre dalla somma delle
 * operazioni realmente collegate (vedi PagamentoRata), non da una proiezione.
 */
class PagamentiRateApiController extends Controller
{
    /**
     * GET /api/v1/pagamenti-rate
     *
     * Paginato (default 15 per pagina, come la lista operazioni). I consumer
     * che necessitano dell'elenco completo (widget dashboard, select nel form
     * operazione) chiedono esplicitamente un per_page più alto.
     *
     * Filtro opzionale ?stato=attivo|completato. Essendo "stato" un attributo
     * calcolato (non una colonna), il filtro si applica in PHP dopo aver
     * caricato le operazioni collegate, poi si pagina manualmente il risultato:
     * a scala personale (poche decine di piani) è semplice e sempre corretto,
     * senza dover tradurre la logica di calcolo dello stato in SQL.
     */
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 15);
            $page = (int) $request->input('page', 1);
            $statoFiltro = $request->input('stato');

            $tutti = PagamentoRata::with('operazioni')
                // id come criterio secondario: created_at ha risoluzione al secondo,
                // quindi non basta da solo a ordinare in modo stabile record ravvicinati
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            if ($statoFiltro) {
                $tutti = $tutti->filter(fn ($p) => $p->stato === $statoFiltro)->values();
            }

            $totale = $tutti->count();
            $ultimaPagina = max(1, (int) ceil($totale / $perPage));
            $items = $tutti->forPage($page, $perPage)->values();

            return response()->json([
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $totale,
                    'last_page' => $ultimaPagina,
                    'has_more' => $page < $ultimaPagina,
                ],
                'count' => $items->count(),
                'message' => 'Pagamenti a rate recuperati con successo'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel recupero dei pagamenti a rate: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * POST /api/v1/pagamenti-rate
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'nome' => 'required|string|max:150',
                'importo_totale' => 'required|numeric|min:0.01',
                'numero_rate' => 'required|integer|min:1',
                'data_inizio' => 'required|date',
            ]);

            $pagamento = PagamentoRata::create($validated);
            $pagamento->load('operazioni');

            return response()->json([
                'success' => true,
                'data' => $pagamento,
                'message' => 'Pagamento a rate creato con successo'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore nella creazione del pagamento a rate: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * GET /api/v1/pagamenti-rate/{id}
     */
    public function show($id)
    {
        try {
            $pagamento = PagamentoRata::with('operazioni.conto', 'operazioni.tags')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $pagamento,
                'message' => 'Pagamento a rate recuperato con successo'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Pagamento a rate non trovato', 'code' => 404], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * PUT /api/v1/pagamenti-rate/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $pagamento = PagamentoRata::findOrFail($id);

            $validated = $request->validate([
                'nome' => 'required|string|max:150',
                'importo_totale' => 'required|numeric|min:0.01',
                'numero_rate' => 'required|integer|min:1',
                'data_inizio' => 'required|date',
            ]);

            $pagamento->update($validated);
            $pagamento->load('operazioni');

            return response()->json([
                'success' => true,
                'data' => $pagamento,
                'message' => 'Pagamento a rate aggiornato con successo'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Pagamento a rate non trovato', 'code' => 404], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore nell\'aggiornamento del pagamento a rate: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * DELETE /api/v1/pagamenti-rate/{id}
     *
     * Bloccata se ci sono operazioni collegate (come per i Tag): l'utente deve
     * prima scollegarle o cancellarle, per non perdere silenziosamente lo storico.
     */
    public function destroy($id)
    {
        try {
            $pagamento = PagamentoRata::findOrFail($id);

            if ($pagamento->operazioni()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Non puoi cancellare un pagamento a rate con operazioni collegate. Scollegale prima.',
                    'code' => 409
                ], 409);
            }

            $pagamento->delete();

            return response()->json([
                'success' => true,
                'message' => 'Pagamento a rate cancellato con successo',
                'data' => ['id' => $id]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Pagamento a rate non trovato', 'code' => 404], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore nella cancellazione del pagamento a rate: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }
}
