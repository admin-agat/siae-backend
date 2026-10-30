<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SupplyPriceHistory extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién creó/editó/borró y cuándo (30/10/2026)

    public $timestamps = false; // usamos changed_at en vez de created_at/updated_at

    protected $table = 'supply_price_history';

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