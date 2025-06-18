<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tontine extends Model
{
    protected $fillable = [
        'name',
        'code',
        'creator_id',
        'amount_fcfa',
        'amount_sats',
        'frequency',
        'max_members',
        'current_members',
        'current_round',
        'status',
        'start_date',
        'next_distribution',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'next_distribution' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'tontines_members')
                    ->withPivot('position', 'has_received', 'joined_at')
                    ->withTimestamps();
    }

    public function payments()
    {
        return $this->hasMany(TontinePayment::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(TontineWithdrawal::class);
    }
}