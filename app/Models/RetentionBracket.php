<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tramo de la retención IR único banano (Art. 27 LRTI): "más de from_boxes hasta to_boxes" → rate.
// Si cambia la ley se actualiza solo esta tabla (06/11/2026).
class RetentionBracket extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = ['from_boxes', 'to_boxes', 'rate'];

    protected $casts = [
        'from_boxes' => 'integer',
        'to_boxes' => 'integer',
        'rate' => 'decimal:4',
    ];
}
