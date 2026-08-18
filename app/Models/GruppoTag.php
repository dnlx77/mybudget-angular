<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToUser;

class GruppoTag extends Model
{
    use HasFactory, BelongsToUser;

    protected $table = 'gruppi_tag';

    protected $fillable = [
        'nome',
    ];

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'rel_gruppi_tag_tags', 'gruppo_tag_id', 'tag_id');
    }
}
