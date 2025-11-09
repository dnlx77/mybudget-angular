<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conto;
use Illuminate\Http\Request;

/**
 * ContiController - API REST per gestione Conti
 * 
 * Questo controller gestisce tutte le operazioni CRUD su conti.
 * Ritorna sempre JSON, non HTML/Blade.
 * 
 * PERCHÉ QUESTO CONTROLLER?
 * - Separazione: API logic separata dalla logica Blade
 * - Riusabilità: Angular, React, mobile possono usare le stesse API
 * - Organizzazione: Facile da manutenere
 */
class ContiApiController extends Controller
{
    /**
     * GET /api/conti
     * Recupera tutti i conti con statistiche
     * 
     * PERCHÉ eager loading (.with('operazioni'))?
     * - Senza: 1 query per conti + N query per operazioni = N+1 problem (lento)
     * - Con eager loading: 1 query per conti + 1 query per operazioni = 2 query (veloce)
     * 
     * PERCHÉ ->get()?
     * - Ritorna collection (array di risultati)
     * - Se volessimo 1 solo risultato useremmo ->first()
     */
    public function index()
    {
        try {
            // Recupera tutti i conti con le loro operazioni già caricate
            $conti = Conto::with('operazioni')->get();

            // Calcoliamo il saldo di ogni conto
            $conti->each(function ($conto) {
                $conto->saldo_totale = $conto->operazioni->sum('importo');
            });

            return response()->json([
                'success' => true,
                'data' => $conti,
                'message' => 'Conti recuperati con successo',
                'count' => $conti->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel recupero dei conti: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * POST /api/conti
     * Crea un nuovo conto
     * 
     * Angular invierà un JSON come:
     * {
     *   "nome": "Conto Corrente"
     * }
     * 
     * PERCHÉ $request->validate()?
     * - Validazione lato server (non basta quella lato client!)
     * - Previene dati sporchi o malformati
     * - Se fallisce, ritorna errore 422 automaticamente
     */
    public function store(Request $request)
    {
        try {
            // Validazione: il campo 'nome' è obbligatorio e max 255 caratteri
            $validated = $request->validate([
                'nome' => 'required|string|max:255|unique:conti,nome'
            ]);

            // Se la validazione passa, creiamo il conto
            $conto = Conto::create($validated);

            // Ritorniamo il conto creato con status HTTP 201 (Created)
            return response()->json([
                'success' => true,
                'data' => $conto,
                'message' => 'Conto creato con successo'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Se la validazione fallisce, Laravel ritorna automaticamente errori formattati
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => 'Errore di validazione'
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nella creazione del conto: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * GET /api/conti/{id}
     * Recupera un conto specifico con le sue operazioni
     * 
     * PERCHÉ findOrFail()?
     * - Se l'id non esiste, ritorna automaticamente HTTP 404
     * - Evita di dover scrivere: if ($conto == null) return error
     */
    public function show($id)
    {
        try {
            // findOrFail lancia un'eccezione se non trova il record
            $conto = Conto::with('operazioni')
                ->findOrFail($id);

            // Calcoliamo il saldo
            $conto->saldo_totale = $conto->operazioni->sum('importo');

            return response()->json([
                'success' => true,
                'data' => $conto,
                'message' => 'Conto recuperato con successo'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Conto non trovato',
                'code' => 404
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel recupero del conto: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * PUT /api/conti/{id}
     * Aggiorna un conto
     * 
     * Angular invierà:
     * {
     *   "nome": "Conto Nuovo Nome"
     * }
     * 
     * PERCHÉ PUT e non PATCH?
     * - PUT: sostituisce l'intera risorsa (tutti i campi)
     * - PATCH: aggiorna solo i campi inviati
     * Noi usiamo PUT per semplicità, ma potresti usare PATCH
     */
    public function update(Request $request, $id)
    {
        try {
            $conto = Conto::findOrFail($id);

            $validated = $request->validate([
                'nome' => 'required|string|max:255|unique:conti,nome,' . $id
                // unique:conti,nome,$id = il nome deve essere unico ECCETTO questo record
            ]);

            $conto->update($validated);

            return response()->json([
                'success' => true,
                'data' => $conto,
                'message' => 'Conto aggiornato con successo'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Conto non trovato',
                'code' => 404
            ], 404);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => 'Errore di validazione'
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nell\'aggiornamento del conto: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * DELETE /api/conti/{id}
     * Cancella un conto
     * 
     * ATTENZIONE: Logica di business importante!
     * Se il conto ha operazioni, potremmo voler impedire la cancellazione
     * Oppure cancellare le operazioni in cascata
     * 
     * Qui implemento una logica "soft": se ha operazioni, ritorno errore
     */
    public function destroy($id)
    {
        try {
            $conto = Conto::findOrFail($id);

            // Controllo se il conto ha operazioni
            if ($conto->operazioni()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Non puoi cancellare un conto con operazioni associate',
                    'code' => 409 // 409 = Conflict
                ], 409);
            }

            $conto->delete();

            return response()->json([
                'success' => true,
                'message' => 'Conto cancellato con successo',
                'data' => ['id' => $id]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Conto non trovato',
                'code' => 404
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nella cancellazione del conto: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }
}
