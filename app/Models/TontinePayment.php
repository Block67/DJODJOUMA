<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TontinePayment extends Model
{
    protected $fillable = [
        'tontine_id',
        'user_id',
        'invoice_id',
        'payment_hash',
        'amount_fcfa',
        'amount_sats',
        'bolt11_invoice',
        'status',
        'expires_at',
        'paid_at',
        'round',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function tontine()
    {
        return $this->belongsTo(Tontine::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}