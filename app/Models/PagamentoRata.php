<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToUser;

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
}
