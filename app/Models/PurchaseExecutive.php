<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// 11/11/2026: comerciales que compran la fruta (lista que maneja ADMIN). En cada compra se eligen
// uno o varios y se guardan por nombre en producer_quota_lines.executive ("MARIA ELENA / JORGE ZERNA").
class PurchaseExecutive extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora: quién agregó o desactivó y cuándo

    public const SEPARADOR = ' / ';

    protected $fillable = ['name', 'status'];

    protected $casts = ['status' => 'boolean'];

    /** "MARIA ELENA / JORGE ZERNA" → ['MARIA ELENA', 'JORGE ZERNA'] */
    public static function nombres(?string $texto): array
    {
        return array_values(array_filter(array_map('trim', explode('/', (string) $texto)), fn ($n) => $n !== ''));
    }
}
