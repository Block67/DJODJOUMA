<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TontineWithdrawal extends Model
{
    protected $fillable = [
        'tontine_id',
        'user_id',
        'amount_fcfa',
        'amount_sats',
        'bolt11_invoice',
        'payment_hash',
        'round',
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