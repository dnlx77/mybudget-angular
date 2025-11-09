<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operazione;
use App\Models\Conto;
use Illuminate\Http\Request;

/**
 * OperazioniApiController - API REST per gestione Operazioni (Transazioni)
 * 
 * Operazioni sono il core dell'app: ogni transazione finanziaria.
 * Hanno relazioni complesse: belongsTo Conto, belongsToMany Tags
 */
class OperazioniApiController extends Controller
{
    /**
     * GET /api/operazioni
     * Recupera operazioni con filtri opzionali
     * 
     * Query parameters opzionali:
     * - anno: filtra per anno
     * - mese: filtra per mese (1-12)
     * - giorno: filtra per giorno
     * - tag: filtra per tag_id
     * - conto: filtra per conto_id
     * 
     * PERCHÉ query parameters e non body?
     * - GET requests non dovrebbero avere body
     * - I parametri diventano parte dell'URL: /api/operazioni?anno=2025&conto=1
     * - Facili da testare nel browser
     * 
     * PERCHÉ usiamo gli scopes che hai già definito?
     * - DRY principle: codice già scritto e testato
     * - Logica di filtro centralizzata nel Model
     */
    public function index(Request $request)
    {
        try {
            $query = Operazione::with(['conto', 'tags']);

            // Applica i filtri usando gli scopes che hai definito
            if ($request->filled('anno') || $request->filled('mese') || 
                $request->filled('giorno') || $request->filled('tag') || 
                $request->filled('conto')) {
                
                $query->cercaOperazioniAvanzato(
                    $request->input('anno'),
                    $request->input('mese'),
                    $request->input('giorno'),
                    $request->input('tag'),
                    $request->input('conto')
                );
            }

            $operazioni = $query->orderBy('data_operazione', 'desc')->get();

            return response()->json([
                'success' => true,
                'data' => $operazioni,
                'count' => $operazioni->count(),
                'message' => 'Operazioni recuperate con successo'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel recupero delle operazioni: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * POST /api/operazioni
     * Crea una nuova operazione (semplice o trasferimento)
     * 
     * Payload di Angular:
     * {
     *   "data_operazione": "2025-11-08",
     *   "importo": 50.00,
     *   "descrizione": "Spesa al supermercato",
     *   "conto_id": 1,
     *   "tags": [1, 2, 3]  <- Array di tag IDs
     * }
     * SCENARIO 2: Trasferimento tra Conti
     * ===================================
     * Payload di Angular:
     * {
     *   "data_operazione": "2025-11-09",
     *   "importo": 500,
     *   "descrizione": "Trasferimento risparmio",
     *   "conto_id": 1,
     *   "conto_destinazione_id": 2,
     *   "tags": [2, 4]
     * }
     * 
     * NOTA: 'tags' non è un campo DB, ma una relazione many-to-many
     * Dobbiamo gestirla DOPO aver creato l'operazione
     */
    public function store(Request $request)
    {
        try {
            // Validazione base
            $validated = $request->validate([
                'data_operazione' => 'required|date',
                'importo' => 'required|numeric',
                'descrizione' => 'required|string|max:500',
                'conto_id' => 'required|exists:conti,id',
                'conto_destinazione_id' => 'nullable|exists:conti,id',  // ← Nuovo!
                'tags' => 'array|exists:tags,id'
            ]);

            // Validazione aggiuntiva: conto_destinazione_id deve essere diverso da conto_id
            if (
                $validated['conto_destinazione_id'] &&
                $validated['conto_destinazione_id'] == $validated['conto_id']
            ) {
                return response()->json([
                    'success' => false,
                    'error' => 'Il conto di destinazione deve essere diverso da quello di origine',
                    'code' => 422
                ], 422);
            }

            // Separa i tags
            $tags = $validated['tags'] ?? [];
            unset($validated['tags']);

            // Determina se è trasferimento
            $isTransferimento = !empty($validated['conto_destinazione_id']);

            if ($isTransferimento) {
                // ============================================================
                // CASO: TRASFERIMENTO (2 operazioni)
                // ============================================================

                // Recupera i nomi dei conti dal DB
                $contoOrigine = Conto::find($validated['conto_id']);
                $contoDestinazione = Conto::find($validated['conto_destinazione_id']);

                // Calcola gli importi
                $importoAssoluto = abs($validated['importo']);  // Valore assoluto (sempre positivo)
                $importoOrigine = -$importoAssoluto;             // Negativo per il conto di origine
                $importoDestinazione = $importoAssoluto;         // Positivo per il conto di destinazione

                // Crea la descrizione con i nomi dei conti
                $descrizioneConTrasferimento = $validated['descrizione'] .
                    " (Trasferimento da " . $contoOrigine->nome .
                    " a " . $contoDestinazione->nome . ")";

                // Crea PRIMA operazione (conto di origine - ADDEBITO)
                $operazione1 = Operazione::create([
                    'data_operazione' => $validated['data_operazione'],
                    'importo' => $importoOrigine,           // ← Negativo
                    'descrizione' => $descrizioneConTrasferimento,
                    'conto_id' => $validated['conto_id'],
                    'trasferimento' => 'T'                  // ← Trasferimento
                ]);

                // Assegna i tags alla prima operazione
                if (!empty($tags)) {
                    $operazione1->tags()->sync($tags);
                }

                // Crea SECONDA operazione (conto di destinazione - ACCREDITO)
                $operazione2 = Operazione::create([
                    'data_operazione' => $validated['data_operazione'],
                    'importo' => $importoDestinazione,      // ← Positivo
                    'descrizione' => $descrizioneConTrasferimento,
                    'conto_id' => $validated['conto_destinazione_id'],
                    'trasferimento' => 'T'                  // ← Trasferimento
                ]);

                // Assegna i tags alla seconda operazione
                if (!empty($tags)) {
                    $operazione2->tags()->sync($tags);
                }

                // Ricarica entrambe le operazioni con relazioni
                $operazione1->load(['tags', 'conto']);
                $operazione2->load(['tags', 'conto']);

                // Ritorna entrambe le operazioni create
                return response()->json([
                    'success' => true,
                    'data' => [
                        'operazione_origine' => $operazione1,
                        'operazione_destinazione' => $operazione2
                    ],
                    'message' => 'Trasferimento creato con successo (2 operazioni inserite)',
                    'trasferimento' => true
                ], 201);
            } else {
                // ============================================================
                // CASO: OPERAZIONE SEMPLICE (1 operazione)
                // ============================================================

                // Rimuovi il campo conto_destinazione_id dal validated
                unset($validated['conto_destinazione_id']);

                // Aggiungi il campo trasferimento
                $validated['trasferimento'] = 'N';  // ← Non è un trasferimento

                // Crea l'operazione
                $operazione = Operazione::create($validated);

                // Assegna i tags
                if (!empty($tags)) {
                    $operazione->tags()->sync($tags);
                }

                // Ricarica con relazioni
                $operazione->load(['tags', 'conto']);

                // Ritorna l'operazione creata
                return response()->json([
                    'success' => true,
                    'data' => $operazione,
                    'message' => 'Operazione creata con successo',
                    'trasferimento' => false
                ], 201);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => 'Errore di validazione'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nella creazione dell\'operazione: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * GET /api/operazioni/{id}
     * Recupera una singola operazione con tutti i dettagli
     */
    public function show($id)
    {
        try {
            $operazione = Operazione::with(['conto', 'tags'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $operazione,
                'message' => 'Operazione recuperata con successo'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Operazione non trovata',
                'code' => 404
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel recupero dell\'operazione: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * PUT /api/operazioni/{id}
     * Aggiorna un'operazione esistente
     * 
     * Payload (tuti i campi opzionali tranne quelli non modificabili):
     * {
     *   "data_operazione": "2025-11-09",
     *   "importo": 75.00,
     *   "descrizione": "Nuova descrizione",
     *   "conto_id": 2,
     *   "tags": [1, 3]
     * }
     */
    public function update(Request $request, $id)
    {
        try {
            $operazione = Operazione::findOrFail($id);

            $validated = $request->validate([
                'data_operazione' => 'date',
                'importo' => 'numeric|regex:/^\d+(\.\d{1,2})?$/',
                'descrizione' => 'string|max:500',
                'conto_id' => 'exists:conti,id',
                'tags' => 'array|exists:tags,id'
            ]);

            // Separa i tags
            $tags = $validated['tags'] ?? null;
            unset($validated['tags']);

            // Aggiorna i campi
            $operazione->update($validated);

            // Aggiorna i tags se forniti
            if ($tags !== null) {
                $operazione->tags()->sync($tags);
            }

            // Ricarica per la risposta
            $operazione->load(['tags', 'conto']);

            return response()->json([
                'success' => true,
                'data' => $operazione,
                'message' => 'Operazione aggiornata con successo'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Operazione non trovata',
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
                'error' => 'Errore nell\'aggiornamento dell\'operazione: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * DELETE /api/operazioni/{id}
     * Cancella un'operazione
     * 
     * IMPORTANTE: Quando cancelli un'operazione:
     * - La relazione many-to-many (tags) viene gestita automaticamente
     *   da Laravel se hai configurato correttamente la migration
     * - Usa softDelete se vuoi mantenere un audit trail
     */
    public function destroy($id)
    {
        try {
            $operazione = Operazione::findOrFail($id);
            
            // Stacca tutti i tags prima di cancellare (optional ma pulito)
            $operazione->tags()->detach();
            
            $operazione->delete();

            return response()->json([
                'success' => true,
                'message' => 'Operazione cancellata con successo',
                'data' => ['id' => $id]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Operazione non trovata',
                'code' => 404
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nella cancellazione dell\'operazione: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * GET /api/operazioni/filtro/avanzato
     * Endpoint speciale per filtri avanzati (opzionale)
     * 
     * PERCHÉ un endpoint separato?
     * - Non necessario per il tuo caso, ma utile per query complesse
     * - /api/operazioni?anno=2025&mese=11 è già abbastanza
     * 
     * Lo includevo per completezza
     */
    public function filtroAvanzato(Request $request)
    {
        try {
            $operazioni = Operazione::with(['conto', 'tags'])
                ->cercaOperazioniAvanzato(
                    $request->input('anno'),
                    $request->input('mese'),
                    $request->input('giorno'),
                    $request->input('tag'),
                    $request->input('conto')
                )
                ->orderBy('data_operazione', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $operazioni,
                'count' => $operazioni->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nei filtri: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }
}
