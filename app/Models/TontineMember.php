<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class TontineMember extends Pivot
{
    protected $table = 'tontines_members';

    protected $fillable = [
        'tontine_id',
        'user_id',
        'position',
        'has_received',
        'joined_at',
    ];

    protected $casts = [
        'has_received' => 'boolean',
        'joined_at' => 'datetime',
    ];
}