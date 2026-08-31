<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SupplyPriceHistory extends Model
{
    public $timestamps = false; // usamos changed_at en vez de created_at/updated_at

    protected $fillable = [
        'supply_id',
        'old_cost',
        'new_cost',
        'changed_at',
        'changed_by',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function supply()
    {
        return $this->belongsTo(Supply::class);
    }
}