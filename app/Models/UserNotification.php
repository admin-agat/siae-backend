<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Aviso de la campanita (12/11/2026). Uno por persona: cada usuario ve y marca leídos solo los suyos.
class UserNotification extends Model
{
    use \App\Models\Concerns\Auditable; // bitácora

    public const ENVIO_BODEGA = 'ENVIO_BODEGA';

    protected $table = 'user_notifications';

    protected $fillable = ['user_id', 'type', 'title', 'body', 'link', 'data', 'producer_quota_line_id', 'created_by', 'read_at'];

    protected $casts = ['read_at' => 'datetime', 'data' => 'array'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
