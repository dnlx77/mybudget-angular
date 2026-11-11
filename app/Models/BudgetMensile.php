<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToUser;

class BudgetMensile extends Model
{
    use HasFactory, BelongsToUser;

    protected $table = 'budget_mensili';

    protected $fillable = [
        'anno',
        'mese',
        'importo',
    ];

    protected $casts = [
        'anno' => 'integer',
        'mese' => 'integer',
        'importo' => 'decimal:2',
    ];
}
