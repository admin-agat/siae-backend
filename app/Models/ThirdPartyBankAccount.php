<?php

// Cuenta bancaria de un proveedor (05/11/2026). Tabla aparte para que no salga en /third-parties:
// solo la ven Contabilidad y el ADMIN (pantalla cuentas_bancarias). Cada cambio queda en la bitácora.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThirdPartyBankAccount extends Model
{
    use \App\Models\Concerns\Auditable;

    // 18/11/2026: + verified_at / verified_by (estado PENDIENTE / VERIFICADA, lo marca Contabilidad)
    protected $fillable = ['third_party_id', 'bank_id', 'account_type', 'account_number', 'holder_id_type', 'holder_id', 'holder_name', 'email', 'updated_by', 'verified_at', 'verified_by'];

    // 09/11/2026 (revisión de seguridad): el número de cuenta se guarda cifrado con APP_KEY.
    // Se lee y escribe normal desde el modelo; una consulta directa a la tabla solo ve el texto cifrado.
    // OJO: sin la APP_KEY de producción (en el .env) no se pueden leer las cuentas.
    protected $casts = [
        'account_number' => 'encrypted',
        'verified_at' => 'datetime',
    ];

    // 18/11/2026 (Peter): si cambia el banco, el tipo, el número o el titular, la cuenta vuelve a PENDIENTE
    // hasta que Contabilidad la verifique otra vez. Una cuenta nueva siempre nace PENDIENTE.
    private const DATOS_DE_PAGO = ['bank_id', 'account_type', 'account_number', 'holder_id_type', 'holder_id', 'holder_name'];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->verified_at = null;
            $c->verified_by = null;
        });
        static::updating(function (self $c) {
            if ($c->isDirty(self::DATOS_DE_PAGO) && !$c->isDirty('verified_at')) {
                $c->verified_at = null;
                $c->verified_by = null;
            }
        });
    }

    public function verificada(): bool
    {
        return $this->verified_at !== null;
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function thirdParty()
    {
        return $this->belongsTo(ThirdParty::class);
    }

    // 18/11/2026: quién la registró o cambió por última vez (Liquidaciones ve "registrada por")
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // 18/11/2026: quién la verificó (Contabilidad o ADMIN)
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
