<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToUser;

class Operazione extends Model
{
    use HasFactory, BelongsToUser;

    protected $table = 'operazioni';      

    protected $fillable = [
        'data_operazione',
        'importo',
        'descrizione',
        'conto_id',
        'trasferimento',
        'transfer_code'
    ];

    public function tags() {
        return $this->belongsToMany(Tag::class, 'rel_operazioni_tags')->withTimestamps();
    }

    public function conto() {
        return $this->belongsTo(Conto::class, 'conto_id');
    }

    public function scopeCercaOperazioniAvanzato($query, $data, $conto_id, $tag, $anno = null, $mese = null)
    {
        // Filtro per anno
        if ($anno) {
            $query->whereYear('data_operazione', $anno);
        }

        // Filtro per mese (funziona solo se anno è impostato)
        if ($mese) {
            $query->whereMonth('data_operazione', $mese);
        }

        // Filtro per data (formato YYYY-MM-DD)
        if ($data) {
            $query->whereDate('data_operazione', $data);
        }

        // Filtro per conto
        if ($conto_id) {
            $query->where('conto_id', '=', $conto_id);
        }

        // Filtro per tag
        if ($tag) {
            $query->join('rel_operazioni_tags', 'operazioni.id', '=', 'rel_operazioni_tags.operazione_id')
                ->where('tag_id', '=', $tag);
        }

        return $query;
    }

    public function scopeCercaOperazioniPrimaDi($query, $data, $conto, $tag) {
        if ($conto)
            $query->where('conto_id', '=', $conto);

        if ($tag)
            $query->join('rel_operazioni_tags', 'operazioni.id', '=', 'rel_operazioni_tags.operazione_id')->where('tag_id', '=', $tag);

        return $query->whereDate('data_operazione', '<=', $data);
    }
}
