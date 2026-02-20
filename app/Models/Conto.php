<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToUser;

class Conto extends Model
{
    use HasFactory, BelongsToUser;

    protected $table = 'conti';

    protected $fillable = [
        'nome',
    ];

    public function operazioni () {
        return $this->hasMany(Operazione::class);
    }
}
