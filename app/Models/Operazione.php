<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operazione extends Model
{
    use HasFactory;

    protected $table = 'operazioni';      

    protected $fillable = [
        'data_operazione',
        'importo',
        'descrizione',
        'conto_id',
        'trasferimento',
    ];

    public function tags() {
        return $this->belongsToMany(Tag::class, 'rel_operazioni_tags');
    }

    public function conto() {
        return $this->belongsTo(Conto::class, 'conto_id');
    }

    public function scopeCercaOperazioniAvanzato($query, $anno, $mese, $giorno, $tag, $conto) {
        if($conto) 
            $query->where('conto_id', '=', $conto);

        if($anno)
            $query->whereYear('data_operazione', $anno);
        
        if($mese)
            $query->whereMonth('data_operazione', $mese);

        if($giorno)
            $query->whereDay('data_operazione', $giorno);

        if($tag) {
            $query->join('rel_operazioni_tags', 'operazioni.id', '=', 'rel_operazioni_tags.operazione_id')->where('tag_id', '=', $tag);
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
