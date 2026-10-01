<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToUser;
use Carbon\Carbon;

class PagamentoRata extends Model
{
    use HasFactory, BelongsToUser;

    protected $table = 'pagamenti_rate';

    protected $fillable = [
        'nome',
        'importo_totale',
        'numero_rate',
        'data_inizio',
    ];

    protected $casts = [
        'importo_totale' => 'decimal:2',
        'data_inizio' => 'date',
    ];

    protected $appends = [
        'importo_pagato',
        'importo_residuo',
        'rate_pagate',
        'stato',
        'rata_mensile',
        'rata_mensile_residua',
    ];

    public function operazioni()
    {
        return $this->hasMany(Operazione::class);
    }

    public function getImportoPagatoAttribute()
    {
        return $this->operazioni->sum(fn ($op) => abs($op->importo));
    }

    public function getImportoResiduoAttribute()
    {
        return max(0, $this->importo_totale - $this->importo_pagato);
    }

    public function getRatePagateAttribute()
    {
        return $this->operazioni->count();
    }

    public function getStatoAttribute()
    {
        return $this->rate_pagate >= $this->numero_rate ? 'completato' : 'attivo';
    }

    public function getRataMensileAttribute()
    {
        return $this->numero_rate > 0 ? round($this->importo_totale / $this->numero_rate, 2) : 0;
    }

    /**
     * Quanto manca a pagare QUESTO mese: la rata intera se nel mese corrente non è
     * ancora stata collegata nessuna operazione, 0 se è già stata pagata (o il piano
     * è completato). Non è "residuo totale / rate rimanenti", ma la parte dovuta ora.
     */
    public function getRataMensileResiduaAttribute()
    {
        if ($this->stato === 'completato') {
            return 0;
        }

        $pagataQuestoMese = $this->operazioni->contains(
            fn ($op) => Carbon::parse($op->data_operazione)->isSameMonth(now())
        );

        return $pagataQuestoMese ? 0 : $this->rata_mensile;
    }
}
