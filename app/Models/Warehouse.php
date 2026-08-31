<?php
 
namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'code',
        'responsible_user_id',
        'zone',
        'status',
    ];
 
    protected $casts = [
        'status' => 'boolean',
    ];
 
    /**
     * Empleado de AGAT responsable de esta bodega (auditoría de envíos/recepción).
     */
    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}