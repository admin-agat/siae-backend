<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Farm extends Model
{
    protected $table = 'farms';

    protected $fillable = [
        'third_party_id', 'name', 'magap_code', 'zone', 'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * El productor dueño de esta finca.
     */
    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }
}