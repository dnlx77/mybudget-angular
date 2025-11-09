<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * AuthController - API REST per Autenticazione
 * 
 * Gestisce:
 * - POST /api/v1/auth/register → Crea nuovo utente + token
 * - POST /api/v1/auth/login    → Login utente + token
 * - POST /api/v1/auth/logout   → Logout + invalida token
 * - GET  /api/v1/auth/me       → Dati utente autenticato
 * 
 * Usa Laravel Sanctum per i token
 * Autentica con: email + password
 */
class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     * Registra un nuovo utente
     * 
     * PAYLOAD:
     * {
     *   "name": "Daniele Rossi",
     *   "email": "daniele@example.com",
     *   "password": "password123",
     *   "password_confirmation": "password123"
     * }
     * 
     * RESPONSE (201):
     * {
     *   "success": true,
     *   "token": "1|abc123xyz...",
     *   "user": {
     *     "id": 1,
     *     "name": "Daniele Rossi",
     *     "email": "daniele@example.com"
     *   },
     *   "message": "Registrazione completata con successo"
     * }
     */
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // createToken() senza parametri extra - usa i campi standard di Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'message' => 'Registrazione completata con successo'
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => 'Errore di validazione'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nella registrazione: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * POST /api/v1/auth/login
     * Autentica un utente esistente
     * 
     * PAYLOAD:
     * {
     *   "email": "daniele@example.com",
     *   "password": "password123"
     * }
     * 
     * RESPONSE (200):
     * {
     *   "success": true,
     *   "token": "1|abc123xyz...",
     *   "user": {
     *     "id": 1,
     *     "name": "Daniele Rossi",
     *     "email": "daniele@example.com"
     *   },
     *   "message": "Login effettuato con successo"
     * }
     */
    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
            ]);

            $user = User::where('email', $validated['email'])->first();

            if (!$user || !Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Credenziali non valide',
                    'code' => 401
                ], 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'message' => 'Login effettuato con successo'
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => 'Errore di validazione'
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel login: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * POST /api/v1/auth/logout
     * Disconnette l'utente (invalida il token)
     * 
     * HEADER RICHIESTO:
     * Authorization: Bearer TOKEN_QUI
     */
    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                    'code' => 401
                ], 401);
            }

            $user->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout effettuato con successo'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore nel logout: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }

    /**
     * GET /api/v1/auth/me
     * Ritorna i dati dell'utente autenticato
     * 
     * HEADER RICHIESTO:
     * Authorization: Bearer TOKEN_QUI
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'Non autenticato',
                    'code' => 401
                ], 401);
            }

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Errore: ' . $e->getMessage(),
                'code' => 500
            ], 500);
        }
    }
}
