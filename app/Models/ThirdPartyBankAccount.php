<?php

// Cuenta bancaria de un proveedor (05/11/2026). Tabla aparte para que no salga en /third-parties:
// solo la ven Contabilidad y el ADMIN (pantalla cuentas_bancarias). Cada cambio queda en la bitácora.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirdPartyBankAccount extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = ['third_party_id', 'bank_id', 'account_type', 'account_number', 'holder_id_type', 'holder_id', 'holder_name', 'email', 'updated_by'];

    // 09/11/2026 (revisión de seguridad): el número de cuenta se guarda cifrado con APP_KEY.
    // Se lee y escribe normal desde el modelo; una consulta directa a la tabla solo ve el texto cifrado.
    // OJO: sin la APP_KEY de producción (en el .env) no se pueden leer las cuentas.
    protected $casts = [
        'account_number' => 'encrypted',
    ];

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }
}
