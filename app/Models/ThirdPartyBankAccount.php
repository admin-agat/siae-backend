<?php

// Cuenta bancaria de un proveedor (05/11/2026). Tabla aparte para que no salga en /third-parties:
// solo la ven Contabilidad y el ADMIN (pantalla cuentas_bancarias). Cada cambio queda en la bitácora.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirdPartyBankAccount extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = ['third_party_id', 'bank_id', 'account_type', 'account_number', 'holder_id_type', 'holder_id', 'holder_name', 'email', 'updated_by'];

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }
}
