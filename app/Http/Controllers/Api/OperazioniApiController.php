<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operazione;
use App\Models\Conto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperazioniApiController extends Controller
{
    /**
     * GET /api/operazioni
     */
    public function index(Request $request)
    {
        try {
            $query = Operazione::with(['conto', 'tags']);

            // Applica filtri (identico all'originale)
            if ($request->anyFilled(['anno', 'mese', 'data', 'conto_id', 'tag'])) {
                $query->cercaOperazioniAvanzato(
                    $request->input('data'),
                    $request->input('conto_id'),
                    $request->input('tag'),
                    $request->input('anno'),
                    $request->input('mese')
                );
            }

            $perPage = $request->input('per_page', 50);
            $page = $request->input('page', 1);

            $operazioni = $query->orderBy('data_operazione', 'desc')
                ->orderBy('id', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Risposta strutturata esattamente come l'originale
            return response()->json([
                'success' => true,
                'data' => $operazioni->items(),
                'pagination' => [
                    'current_page' => $operazioni->currentPage(),
                    'per_page' => $operazioni->perPage(),
                    'total' => $operazioni->total(),
                    'last_page' => $operazioni->lastPage(),
                    'has_more' => $operazioni->hasMorePages(),
                ],
                'count' => count($operazioni->items()),
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
     */
    public function store(Request $request)
    {
        try {
            // 1. Validazione (Tags REQUIRED come da originale)
            $validated = $request->validate([
                'data_operazione' => 'required|date',
                'importo' => 'required|numeric',
                'descrizione' => 'nullable|string|max:500',
                'conto_id' => 'required|exists:conti,id',
                'conto_destinazione_id' => 'nullable|different:conto_id|exists:conti,id',
                'tags' => 'required|array|min:1', // <-- RIPRISTINATO REQUIRED
                'tags.*' => 'exists:tags,id'
            ]);

            // 2. Controllo coerenza destinazione (come originale)
            if (
                !empty($validated['conto_destinazione_id']) &&
                $validated['conto_destinazione_id'] == $validated['conto_id']
            ) {
                return response()->json([
                    'success' => false,
                    'error' => 'Il conto di destinazione deve essere diverso da quello di origine',
                    'code' => 422
                ], 422);
            }

            // 3. Separazione Tags (IMPORTANTE: evitare errore column not found)
            $tags = $validated['tags'];
            unset($validated['tags']); // <-- FONDAMENTALE

            $isTrasferimento = !empty($validated['conto_destinazione_id']);

            return DB::transaction(function () use ($validated, $tags, $isTrasferimento) {

                if ($isTrasferimento) {
                    // --- CASO TRASFERIMENTO ---
                    $transferUuid = Str::uuid();
                    $importo = abs($validated['importo']); // Normalizziamo

                    // 🟢 RECUPERO NOMI CONTI PER DESCRIZIONE PARLANTE 🟢
                    $contoOrigine = Conto::find($validated['conto_id']);
                    $contoDestinazione = Conto::find($validated['conto_destinazione_id']);

                    $nomeOrigine = $contoOrigine ? $contoOrigine->nome : '???';
                    $nomeDestinazione = $contoDestinazione ? $contoDestinazione->nome : '???';

                    // Costruisco la dicitura: (Trasferimento da A a B)
                    $dicituraTransfer = "(Trasferimento da {$nomeOrigine} a {$nomeDestinazione})";

                    // Metto la dicitura DOPO della descrizione utente (se presente)
                    $descrizioneFinale = $validated['descrizione']
                        ? $validated['descrizione']. " ". $dicituraTransfer
                        : $dicituraTransfer;

                    // Rimuoviamo conto_destinazione_id dai dati da salvare nell'operazione 1
                    unset($validated['conto_destinazione_id']);

                    // 1. Uscita (Negativa)
                    // Usiamo array_merge per sovrascrivere i campi necessari
                    $op1 = Operazione::create(array_merge($validated, [
                        'importo' => -$importo,
                        'descrizione' => $descrizioneFinale, // ⬅️ Usa la nuova descrizione
                        'trasferimento' => 'T',
                        'transfer_code' => $transferUuid
                    ]));
                    $op1->tags()->sync($tags);

                    // 2. Entrata (Positiva)
                    $op2 = Operazione::create(array_merge($validated, [
                        'importo' => $importo,
                        'conto_id' => request('conto_destinazione_id'), // Recuperiamo la destinazione originale
                        'descrizione' => $descrizioneFinale, // ⬅️ Usa la nuova descrizione
                        'trasferimento' => 'T',
                        'transfer_code' => $transferUuid
                    ]));
                    $op2->tags()->sync($tags);

                    // Ricarica relazioni
                    $op1->load(['tags', 'conto']);
                    $op2->load(['tags', 'conto']);

                    return response()->json([
                        'success' => true,
                        'data' => [
                            'operazione_origine' => $op1,
                            'operazione_destinazione' => $op2
                        ],
                        'message' => 'Trasferimento creato con successo',
                        'trasferimento' => true
                    ], 201);
                } else {
                    // --- CASO NORMALE ---
                    // Pulizia campi non necessari
                    unset($validated['conto_destinazione_id']);

                    $op = Operazione::create(array_merge($validated, [
                        'trasferimento' => 'N',
                        'transfer_code' => null
                    ]));

                    $op->tags()->sync($tags);
                    $op->load(['tags', 'conto']);

                    return response()->json([
                        'success' => true,
                        'data' => $op,
                        'message' => 'Operazione creata con successo',
                        'trasferimento' => false
                    ], 201);
                }
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore nella creazione: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * PUT /api/operazioni/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $operazione = Operazione::findOrFail($id);

            // Validazione (Tags REQUIRED come da originale)
            $validated = $request->validate([
                'data_operazione' => 'required|date',
                'importo' => 'required|numeric',
                'descrizione' => 'nullable|string|max:500',
                'conto_id' => 'required|exists:conti,id',
                'tags' => 'required|array|min:1', // <-- RIPRISTINATO REQUIRED
                'tags.*' => 'exists:tags,id'
            ]);

            // Separazione tags
            $tags = $validated['tags'];
            unset($validated['tags']); // <-- FONDAMENTALE

            return DB::transaction(function () use ($operazione, $validated, $tags) {
                // Aggiorniamo l'operazione corrente
                $operazione->update($validated);
                $operazione->tags()->sync($tags);

                // Sincronizzazione Gemella (se esiste)
                if ($operazione->trasferimento === 'T' && $operazione->transfer_code) {
                    $gemella = Operazione::where('transfer_code', $operazione->transfer_code)
                        ->where('id', '!=', $operazione->id)
                        ->first();

                    if ($gemella) {
                        $gemella->update([
                            'data_operazione' => $validated['data_operazione'],
                            'importo' => -$validated['importo'], // Segno invertito
                            'descrizione' => $validated['descrizione'], // Propaghiamo descrizione
                        ]);
                        $gemella->tags()->sync($tags); // Propaghiamo tags
                    }
                }

                $operazione->load(['conto', 'tags']);

                return response()->json([
                    'success' => true,
                    'data' => $operazione,
                    'message' => 'Operazione aggiornata con successo'
                ]);
            });
        } catch (\Illuminate\Validation\ValidationException $e) { // Aggiunto catch specifico validation
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * DELETE /api/operazioni/{id}
     */
    public function destroy($id)
    {
        try {
            return DB::transaction(function () use ($id) {
                $operazione = Operazione::findOrFail($id);
                $message = "Operazione cancellata con successo";

                if ($operazione->trasferimento === 'T' && $operazione->transfer_code) {
                    // Cancella tutte le operazioni collegate (anche la gemella)
                    Operazione::where('transfer_code', $operazione->transfer_code)->delete();
                    // Nota: detach dei tags avviene automaticamente se il DB ha foreign key cascade, 
                    // altrimenti Laravel Model event potrebbe gestirlo. 
                    // Per sicurezza, se non hai cascade nel DB, detach non serve su delete multiplo builder,
                    // ma serve se iteri. Con ->delete() sul builder è veloce.
                } else {
                    $operazione->tags()->detach();
                    $operazione->delete();
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => ['id' => $id]
                ]);
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Operazione non trovata', 'code' => 404], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * GET /api/v1/operazioni/statistiche/totali
     * Versione OTTIMIZZATA ma con LOGICA ORIGINALE per i trasferimenti
     */
    public function statisticheTotali(Request $request)
    {
        try {
            $query = Operazione::query();

            // 1. Applica filtri (identico all'index)
            if ($request->anyFilled(['anno', 'mese', 'data', 'conto_id', 'tag'])) {
                $query->cercaOperazioniAvanzato(
                    $request->input('data'),
                    $request->input('conto_id'),
                    $request->input('tag'),
                    $request->input('anno'),
                    $request->input('mese')
                );
            }

            // 2. Logica esclusione trasferimenti (IDENTICA all'originale)
            // Se non è selezionato un conto specifico, escludi i trasferimenti
            if (!($request->has('conto_id') && $request->input('conto_id'))) {
                $query->where('trasferimento', '!=', 'T');
            }

            // 3. Calcoli ottimizzati (SQL invece di RAM)
            $guadagno = (clone $query)->where('importo', '>', 0)->sum('importo');
            $spese = (clone $query)->where('importo', '<', 0)->sum('importo');

            // $spese viene restituito negativo dal DB (es. -100), ne prendiamo il valore assoluto
            $speseAbs = abs($spese);

            // Il saldo è la somma algebrica (es. 500 + (-100) = 400)
            // Nota: se usi brick/money o simili, qui la logica cambierebbe, ma per ora usiamo i float/decimal del DB
            $saldo = $guadagno + $spese;

            return response()->json([
                'success' => true,
                'data' => [
                    'guadagno' => $guadagno,
                    'spese' => $speseAbs,
                    'saldo' => $saldo,
                ],
                'message' => 'Statistiche calcolate'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/operazioni/{id}
     */
    public function show($id)
    {
        try {
            $operazione = Operazione::with(['conto', 'tags'])->findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $operazione,
                'message' => 'Operazione recuperata con successo'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Operazione non trovata', 'code' => 404], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }
}
