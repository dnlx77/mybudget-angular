<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToUser
{
    /**
     * Il "booted" viene eseguito ogni volta che il Model viene inizializzato.
     */
    protected static function booted()
    {
        // 1. GLOBAL SCOPE (Lettura)
        // Aggiunge automaticamente "WHERE user_id = X" a TUTTE le query (get, find, all, ecc.)
        static::addGlobalScope('user', function (Builder $builder) {
            if (auth()->check()) {
                $builder->where('user_id', auth()->id());
            }
        });

        // 2. CREATING EVENT (Scrittura)
        // Quando crei un nuovo record, ci inietta automaticamente l'ID dell'utente loggato
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->user_id = auth()->id();
            }
        });
    }

    // Relazione per comodità
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
