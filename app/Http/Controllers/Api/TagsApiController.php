<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;

/**
 * TagsApiController - API REST per gestione Tags
 * 
 * I tag sono semplici: hanno un nome e una relazione many-to-many con Operazioni.
 * Controller più semplice rispetto a ContiController e OperazioniController.
 */
class TagsApiController extends Controller
{
    /**
     * GET /api/tags
     * Recupera tutti i tags con conteggio operazioni associate
     */
    public function index()
    {
        try {
            // withCount() = aggiunge un campo 'operazioni_count' senza caricare tutte le operazioni
            // È una query ottimizzata (1 query + 1 subquery COUNT)
            $tags = Tag::withCount('operazioni')->get();

            return response()->json([
                'success' => true,
                'data' => $tags,
                'count' => $tags->count(),
                'message' => 'Tags recuperati con successo'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel recupero dei tags: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * POST /api/tags
     * Crea un nuovo tag
     * 
     * Payload:
     * {
     *   "nome": "Alimentari"
     * }
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'nome' => 'required|string|max:100|unique:tags,nome'
            ]);

            $tag = Tag::create($validated);

            return response()->json([
                'success' => true,
                'data' => $tag,
                'message' => 'Tag creato con successo'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => 'Errore di validazione'
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nella creazione del tag: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * GET /api/tags/{id}
     * Recupera un tag specifico con le operazioni associate
     */
    public function show($id)
    {
        try {
            $tag = Tag::with('operazioni')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $tag,
                'message' => 'Tag recuperato con successo'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Tag non trovato',
                'code' => 404
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel recupero del tag: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * PUT /api/tags/{id}
     * Aggiorna un tag
     */
    public function update(Request $request, $id)
    {
        try {
            $tag = Tag::findOrFail($id);

            $validated = $request->validate([
                'nome' => 'required|string|max:100|unique:tags,nome,' . $id
            ]);

            $tag->update($validated);

            return response()->json([
                'success' => true,
                'data' => $tag,
                'message' => 'Tag aggiornato con successo'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Tag non trovato',
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
                'error' => 'Errore nell\'aggiornamento del tag: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * DELETE /api/tags/{id}
     * Cancella un tag
     * 
     * LOGICA IMPORTANTE: Se il tag è associato a operazioni?
     * 
     * Opzione 1: Impedisci la cancellazione (come farò qui)
     * Opzione 2: Permetti, la relazione many-to-many gestisce tutto
     * 
     * Scelgo opzione 1 per prevenire errori accidentali
     */
    public function destroy($id)
    {
        try {
            $tag = Tag::findOrFail($id);

            // Controlla se il tag è usato
            if ($tag->operazioni()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Non puoi cancellare un tag usato in operazioni. Rimuovilo dalle operazioni prima.',
                    'code' => 409
                ], 409);
            }

            $tag->delete();

            return response()->json([
                'success' => true,
                'message' => 'Tag cancellato con successo',
                'data' => ['id' => $id]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Tag non trovato',
                'code' => 404
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nella cancellazione del tag: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }
}
