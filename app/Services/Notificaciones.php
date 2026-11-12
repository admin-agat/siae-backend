<?php

namespace App\Services;

use App\Models\ProducerQuotaLine;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\Warehouse;

// Avisos de la campanita (12/11/2026, diseño aprobado por Peter).
// ENVIO_BODEGA: Comercial envió a retirar una compra. Le llega al bodeguero de esa bodega y a los
// coordinadores de inventario que la manejan (compra sin bodega fija: a todos ellos). Solo a usuarios
// activos que pueden abrir Despacho de materiales.
class Notificaciones
{
    public static function envioBodega(ProducerQuotaLine $linea, ?User $por): void
    {
        $linea->loadMissing(['quota.thirdParty:id,name', 'producer:id,name', 'pickupWarehouse:id,name', 'brand:id,name,code',
            'block.shipment.vessel:id,name']);
        $bodegaId = $linea->pickup_warehouse_id ? (int) $linea->pickup_warehouse_id : null;

        $bodegueros = User::where('status', true)->where('role', 'BODEGUERO')
            ->whereIn('id', Warehouse::when($bodegaId, fn ($q) => $q->whereKey($bodegaId))
                ->whereNotNull('responsible_user_id')->select('responsible_user_id'))
            ->get();
        $coordinadores = User::where('status', true)->where('role', 'COORDINADOR_INVENTARIO')->get()
            ->filter(fn ($u) => !$bodegaId || $u->puedeBodega($bodegaId));
        $destinatarios = $bodegueros->merge($coordinadores)->unique('id')->filter(fn ($u) => $u->puede('despacho'));
        if ($destinatarios->isEmpty()) {
            return;
        }

        $envio = $linea->block?->shipment;
        $nave = $envio ? trim(($envio->vessel?->name ?: $envio->vessel_name) . ' ' . ($envio->voyage ?? '')) : null;
        $vendedor = $linea->quota?->thirdParty?->name;
        $productor = $linea->producer?->name;
        $partes = array_filter([
            $vendedor . ($productor && $productor !== $vendedor ? " (PRODUCTOR: $productor)" : ''),
            $nave ? "NAVE $nave" : null,
            'WK' . $linea->quota?->week_number,
            $linea->brand ? 'MARCA ' . $linea->brand->name : null,
            number_format((int) $linea->quantity_cajas, 0, ',', '.') . ' CAJAS',
        ]);
        $titulo = 'ENVIADO A RETIRAR · ' . ($linea->pickupWarehouse?->name ? 'BODEGA ' . $linea->pickupWarehouse->name : 'CUALQUIER BODEGA');

        foreach ($destinatarios as $u) {
            UserNotification::create([ // uno por uno: queda en la bitácora
                'user_id'                => $u->id,
                'type'                   => UserNotification::ENVIO_BODEGA,
                'title'                  => mb_substr($titulo, 0, 150),
                'body'                   => mb_substr(implode(' · ', $partes), 0, 500),
                'link'                   => '/despacho-materiales',
                // lo mismo que manda "Despachar" de la bandeja Por despachar
                'data'                   => [
                    'warehouse_id'      => $bodegaId,
                    'third_party_id'    => $linea->quota?->third_party_id,
                    'producer_quota_id' => $linea->producer_quota_id,
                    'brand_code'        => $linea->variety,
                ],
                'producer_quota_line_id' => $linea->id,
                'created_by'             => $por?->id,
            ]);
        }
    }

    /** Comercial quitó el envío: los avisos sin leer de esa compra se marcan leídos, uno por uno (bitácora). */
    public static function envioQuitado(ProducerQuotaLine $linea): void
    {
        UserNotification::where('type', UserNotification::ENVIO_BODEGA)->where('producer_quota_line_id', $linea->id)
            ->whereNull('read_at')->get()->each(fn ($a) => $a->update(['read_at' => now()]));
    }
}
