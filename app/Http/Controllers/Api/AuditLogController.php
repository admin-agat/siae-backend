<?php

// BITÁCORA DE AUDITORÍA (30/10/2026) — solo ADMIN, solo lectura
// Lo que cada usuario hizo en el sistema con fecha y hora (lo escribe el trait Auditable).
//
//   GET /audit-logs   filtros: user_id, table_name, action, record_id, desde, hasta (Y-m-d), q (texto en los
//                     cambios), page; 50 por página, lo más reciente primero. Trae además los usuarios y
//                     las tablas que aparecen en la bitácora (para los filtros de la pantalla).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $filtros = $request->validate([
            'user_id'    => 'nullable|integer',
            'table_name' => 'nullable|string|max:60',
            'action'     => 'nullable|string|max:20',
            'record_id'  => 'nullable|integer',
            'desde'      => 'nullable|date',
            'hasta'      => 'nullable|date',
            'q'          => 'nullable|string|max:100',
        ]);

        $query = AuditLog::query()
            ->when($filtros['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filtros['table_name'] ?? null, fn ($q, $v) => $q->where('table_name', $v))
            ->when($filtros['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filtros['record_id'] ?? null, fn ($q, $v) => $q->where('record_id', $v))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v . ' 00:00:00'))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'))
            // Texto libre dentro de los cambios (ej. un número de booking o el nombre de un productor)
            ->when($filtros['q'] ?? null, fn ($q, $v) => $q->whereRaw('changes::text ILIKE ?', ['%' . $v . '%']))
            ->orderByDesc('id');

        $pagina = $query->paginate(50);

        return response()->json([
            'data'         => $pagina->items(),
            'current_page' => $pagina->currentPage(),
            'last_page'    => $pagina->lastPage(),
            'total'        => $pagina->total(),
            'users'        => User::orderBy('name')->get(['id', 'name', 'role']),
            'tables'       => AuditLog::whereNotNull('table_name')->distinct()->orderBy('table_name')->pluck('table_name'),
        ]);
    }
}
