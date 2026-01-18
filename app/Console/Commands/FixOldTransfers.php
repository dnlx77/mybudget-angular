<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Operazione;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class FixOldTransfers extends Command
{
    // Il nome del comando da lanciare nel terminale
    protected $signature = 'db:fix-transfers';
    protected $description = 'Collega i vecchi trasferimenti assegnando un transfer_code univoco';

    public function handle()
    {
        $this->info("Inizio scansione storico (12 anni)...");

        // 1. Prendi tutte le USCITE che sono segnate come TRASFERIMENTO ('T')
        // e che non hanno ancora un codice (transfer_code is NULL)
        $uscite = Operazione::where('trasferimento', 'T')
            ->where('importo', '<', 0)
            ->whereNull('transfer_code')
            ->orderBy('data_operazione', 'desc') // Partiamo dai più recenti
            ->get();

        $count = 0;
        $orfani = 0;

        $bar = $this->output->createProgressBar(count($uscite));
        $bar->start();

        foreach ($uscite as $uscita) {
            // 2. Per ogni uscita, cerca l'entrata gemella
            $entrata = Operazione::where('trasferimento', 'T') // Deve essere anche lei un trasferimento
                ->where('importo', abs($uscita->importo)) // Importo opposto esatto (es. +50)
                ->where('data_operazione', $uscita->data_operazione) // Stesso giorno
                ->where('id', '!=', $uscita->id) // Non se stessa
                ->whereNull('transfer_code') // Non ancora assegnata
                ->first();

            if ($entrata) {
                // TROVATA! Creiamo il legame
                $uuid = Str::uuid();

                DB::transaction(function () use ($uscita, $entrata, $uuid) {
                    // Aggiorniamo uscita
                    $uscita->transfer_code = $uuid;
                    $uscita->save();

                    // Aggiorniamo entrata
                    $entrata->transfer_code = $uuid;
                    $entrata->save();
                });

                $count++;
            } else {
                $orfani++;
                // Puoi decommentare questa riga se vuoi vedere i dettagli degli orfani a video
                // $this->warn("\nOrfano: ID {$uscita->id} del {$uscita->data_operazione} per {$uscita->importo}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Finito!");
        $this->info("Coppie collegate con successo: $count (totale operazioni: " . ($count * 2) . ")");

        if ($orfani > 0) {
            $this->warn("Attenzione: $orfani trasferimenti in uscita non hanno trovato una corrispondenza (orfani).");
            $this->line("Questo succede se avevi cancellato manualmente solo metà del trasferimento in passato.");
        }
    }
}
