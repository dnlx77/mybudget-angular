<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GruppoTag;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GruppiTagApiController - API REST per gestione "gruppi tag" (tag virtuali)
 *
 * Un gruppo tag è una raccolta di tag salvata con un nome: applicandola come
 * filtro sulle operazioni equivale a filtrare per tutti i tag del gruppo insieme.
 */
class GruppiTagApiController extends Controller
{
    /**
     * GET /api/v1/gruppi-tag
     */
    public function index()
    {
        try {
            $gruppi = GruppoTag::with('tags')->orderBy('nome')->get();

            return response()->json([
                'success' => true,
                'data' => $gruppi,
                'count' => $gruppi->count(),
                'message' => 'Gruppi tag recuperati con successo'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel recupero dei gruppi tag: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * POST /api/v1/gruppi-tag
     * Payload: { "nome": "Spese auto", "tags": [1, 4, 7] }
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'nome' => ['required', 'string', 'max:100', Rule::unique('gruppi_tag', 'nome')->where('user_id', auth()->id())],
                'tags' => 'required|array|min:1',
                'tags.*' => 'exists:tags,id',
            ]);

            $gruppo = GruppoTag::create(['nome' => $validated['nome']]);
            $gruppo->tags()->sync($validated['tags']);
            $gruppo->load('tags');

            return response()->json([
                'success' => true,
                'data' => $gruppo,
                'message' => 'Gruppo tag creato con successo'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore nella creazione del gruppo tag: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * GET /api/v1/gruppi-tag/{id}
     */
    public function show($id)
    {
        try {
            $gruppo = GruppoTag::with('tags')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $gruppo,
                'message' => 'Gruppo tag recuperato con successo'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Gruppo tag non trovato', 'code' => 404], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * PUT /api/v1/gruppi-tag/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $gruppo = GruppoTag::findOrFail($id);

            $validated = $request->validate([
                'nome' => ['required', 'string', 'max:100', Rule::unique('gruppi_tag', 'nome')->where('user_id', auth()->id())->ignore($gruppo->id)],
                'tags' => 'required|array|min:1',
                'tags.*' => 'exists:tags,id',
            ]);

            $gruppo->update(['nome' => $validated['nome']]);
            $gruppo->tags()->sync($validated['tags']);
            $gruppo->load('tags');

            return response()->json([
                'success' => true,
                'data' => $gruppo,
                'message' => 'Gruppo tag aggiornato con successo'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Gruppo tag non trovato', 'code' => 404], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore nell\'aggiornamento del gruppo tag: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }

    /**
     * DELETE /api/v1/gruppi-tag/{id}
     */
    public function destroy($id)
    {
        try {
            $gruppo = GruppoTag::findOrFail($id);
            $gruppo->tags()->detach();
            $gruppo->delete();

            return response()->json([
                'success' => true,
                'message' => 'Gruppo tag cancellato con successo',
                'data' => ['id' => $id]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'error' => 'Gruppo tag non trovato', 'code' => 404], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Errore nella cancellazione del gruppo tag: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }
}
