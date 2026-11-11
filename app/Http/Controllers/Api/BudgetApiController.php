<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetMensile;
use App\Models\Operazione;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * BudgetApiController - budget mensile complessivo
 *
 * Lo "speso" segue le stesse regole della card Uscite della dashboard
 * (importo < 0, trasferimenti esclusi), così i due numeri combaciano.
 * La proposta e il riferimento per tag sono la media dei 3 mesi precedenti.
 */
class BudgetApiController extends Controller
{
    private const MESI_RIFERIMENTO = 3;

    /**
     * GET /api/v1/budget?anno=2026&mese=11[&tag=1,2,3]
     */
    public function show(Request $request)
    {
        try {
            $validated = $request->validate([
                'anno' => 'required|integer|min:2000|max:2100',
                'mese' => 'required|integer|min:1|max:12',
                'tag' => 'nullable|string',
            ]);

            $inizioMese = Carbon::create($validated['anno'], $validated['mese'], 1)->startOfDay();
            $tagIds = $this->parseTagIds($validated['tag'] ?? null);

            $budget = BudgetMensile::where('anno', $validated['anno'])
                ->where('mese', $validated['mese'])
                ->first();

            // Mesi di riferimento: i 3 precedenti, ma solo quelli con almeno una spesa
            // (un mese vuoto significa "app non ancora usata", non "spesa zero")
            $mesiRiferimento = [];
            for ($i = self::MESI_RIFERIMENTO; $i >= 1; $i--) {
                $mese = $inizioMese->copy()->subMonthsNoOverflow($i);
                $speso = $this->spesoNelMese($mese);
                if ($speso > 0) {
                    $mesiRiferimento[] = [
                        'inizio' => $mese,
                        'etichetta' => $this->etichettaMese($mese),
                        'speso' => $speso,
                    ];
                }
            }

            $proposta = null;
            if (count($mesiRiferimento) > 0) {
                $media = array_sum(array_column($mesiRiferimento, 'speso')) / count($mesiRiferimento);
                $proposta = round($media / 10) * 10;
            }

            $tag = null;
            if (!empty($tagIds)) {
                $tag = $this->datiTag($inizioMese, $tagIds, $mesiRiferimento);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'anno' => (int) $validated['anno'],
                    'mese' => (int) $validated['mese'],
                    'etichetta' => $this->etichettaMese($inizioMese),
                    'budget' => $budget ? (float) $budget->importo : null,
                    'proposta' => $proposta,
                    'mesi_riferimento' => $this->senzaInizio($mesiRiferimento),
                    'speso' => $this->spesoNelMese($inizioMese),
                    'giorni_mese' => $inizioMese->daysInMonth,
                    'giorni_trascorsi' => $this->giorniTrascorsi($inizioMese),
                    'ritmo' => $this->ritmo($inizioMese, $mesiRiferimento),
                    'tag' => $tag,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['success' => false, 'error' => 'Errore nel calcolo del budget: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/v1/budget
     * Payload: { "anno": 2026, "mese": 11, "importo": 1850 }
     */
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'anno' => 'required|integer|min:2000|max:2100',
                'mese' => 'required|integer|min:1|max:12',
                'importo' => 'required|numeric|min:0.01|max:99999999',
            ]);

            // Il global scope di BelongsToUser limita la ricerca all'utente corrente
            // e l'evento "creating" assegna user_id al nuovo record
            $budget = BudgetMensile::updateOrCreate(
                ['anno' => $validated['anno'], 'mese' => $validated['mese']],
                ['importo' => $validated['importo']]
            );

            return response()->json([
                'success' => true,
                'data' => $budget,
                'message' => 'Budget salvato con successo',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => 'Errore di validazione'], 422);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['success' => false, 'error' => 'Errore nel salvataggio del budget: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Speso nel mese su uno o più tag, e come riferimento la media degli stessi
     * mesi usati per la proposta. Qui un mese a zero conta: se quel mese hai usato
     * l'app ma non hai speso in quel tag, la spesa nel tag era davvero zero.
     * Un'operazione con più tag del filtro conta una volta sola (whereHas).
     */
    private function datiTag(Carbon $inizioMese, array $tagIds, array $mesiRiferimento): array
    {
        $mesi = array_map(fn ($m) => [
            'inizio' => $m['inizio'],
            'etichetta' => $m['etichetta'],
            'speso' => $this->spesoNelMese($m['inizio'], $tagIds),
        ], $mesiRiferimento);

        $riferimento = count($mesi) > 0
            ? round(array_sum(array_column($mesi, 'speso')) / count($mesi), 2)
            : null;

        return [
            'speso' => $this->spesoNelMese($inizioMese, $tagIds),
            'riferimento' => $riferimento,
            'mesi_riferimento' => $this->senzaInizio($mesi),
            'ritmo' => $this->ritmo($inizioMese, $mesi, $tagIds),
        ];
    }

    /**
     * Percentuale della spesa mensile che di solito era già stata fatta a questo
     * giorno del mese, come media dei mesi di riferimento ("ritmo storico").
     * Tiene conto delle spese concentrate a inizio mese (mutuo, abbonamenti),
     * che un ritmo lineare (giorni trascorsi / giorni del mese) segnalerebbe
     * come spesa troppo veloce. Senza mesi utilizzabili si torna al lineare.
     *
     * $mesi: [['inizio' => Carbon, 'speso' => totale del mese nello stesso ambito di $tagIds]]
     */
    private function ritmo(Carbon $inizioMese, array $mesi, array $tagIds = []): array
    {
        $giorni = $this->giorniTrascorsi($inizioMese);
        $giorniMese = $inizioMese->daysInMonth;

        // Mese futuro o già concluso: niente da stimare
        if ($giorni === 0 || $giorni >= $giorniMese) {
            return ['percentuale' => $giorni === 0 ? 0 : 100, 'tipo' => 'lineare'];
        }

        $quote = [];
        foreach ($mesi as $m) {
            if ($m['speso'] <= 0) continue;

            // Stesso giorno nel mese di riferimento (es. 31 in un mese da 30 → ultimo giorno)
            $fino = $m['inizio']->copy()->day(min($giorni, $m['inizio']->daysInMonth));
            $quote[] = $this->spesoTra($m['inizio'], $fino, $tagIds) / $m['speso'];
        }

        if (empty($quote)) {
            return ['percentuale' => round($giorni / $giorniMese * 100, 1), 'tipo' => 'lineare'];
        }

        return ['percentuale' => round(array_sum($quote) / count($quote) * 100, 1), 'tipo' => 'storico'];
    }

    private function spesoNelMese(Carbon $inizioMese, array $tagIds = []): float
    {
        return $this->spesoTra($inizioMese, $inizioMese->copy()->endOfMonth(), $tagIds);
    }

    private function spesoTra(Carbon $inizio, Carbon $fine, array $tagIds = []): float
    {
        $query = Operazione::where('importo', '<', 0)
            ->where('trasferimento', '!=', 'T')
            ->whereBetween('data_operazione', [$inizio->toDateString(), $fine->toDateString()]);

        if (!empty($tagIds)) {
            $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds));
        }

        return round(abs((float) $query->sum('importo')), 2);
    }

    private function senzaInizio(array $mesi): array
    {
        return array_map(fn ($m) => ['etichetta' => $m['etichetta'], 'speso' => $m['speso']], $mesi);
    }

    /**
     * Giorni del mese già trascorsi, per il marcatore del "ritmo":
     * mese passato = tutti, mese futuro = nessuno, mese corrente = giorno di oggi.
     */
    private function giorniTrascorsi(Carbon $inizioMese): int
    {
        $oggi = Carbon::today();

        if ($inizioMese->isSameMonth($oggi)) {
            return $oggi->day;
        }

        return $inizioMese->lessThan($oggi) ? $inizioMese->daysInMonth : 0;
    }

    private function etichettaMese(Carbon $mese): string
    {
        return ucfirst($mese->copy()->locale('it')->translatedFormat('F Y'));
    }

    private function parseTagIds(?string $tag): array
    {
        if (!$tag) {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', explode(',', $tag)),
            fn ($id) => $id > 0
        ));
    }
}
