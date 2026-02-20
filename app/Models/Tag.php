<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToUser;

class Tag extends Model
{
    use HasFactory, BelongsToUser;

    protected $fillable = [
        'nome',
    ];

    public function operazioni()
    {
        return $this->belongsToMany(Operazione::class, 'rel_operazioni_tags');
    }
}
