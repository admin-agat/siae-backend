<?php

// CAMPANITA DE NOTIFICACIONES (12/11/2026)
// Ubicación: app/Http/Controllers/Api/NotificationController.php
//
//   GET   /notifications              → los últimos 30 avisos del usuario (no leídos primero)
//   GET   /notifications/count        → cuántos no leídos tiene (la campanita pregunta cada minuto)
//   PATCH /notifications/{id}/read    → marcar uno como leído
//   PATCH /notifications/read-all     → marcar todos como leídos
// Cada usuario solo ve y marca los suyos (se filtra siempre por user_id). No se borran.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $avisos = UserNotification::with('creator:id,name')->where('user_id', $request->user()->id)
            ->orderByRaw('read_at IS NOT NULL')->orderByDesc('created_at')->orderByDesc('id')
            ->limit(30)->get();

        return response()->json($avisos->map(fn ($a) => [
            'id'         => $a->id,
            'type'       => $a->type,
            'title'      => $a->title,
            'body'       => $a->body,
            'link'       => $a->link,
            'data'       => $a->data,
            'created_by' => $a->creator?->name,
            'created_at' => $a->created_at?->toIso8601String(),
            'read_at'    => $a->read_at?->toIso8601String(),
        ]));
    }

    public function count(Request $request)
    {
        return response()->json(['count' => UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()]);
    }

    public function read(Request $request, $id)
    {
        $aviso = UserNotification::where('user_id', $request->user()->id)->findOrFail($id);
        if (!$aviso->read_at) {
            $aviso->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        // uno por uno: queda en la bitácora
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->get()
            ->each(fn ($a) => $a->update(['read_at' => now()]));

        return response()->json(['ok' => true]);
    }
}
