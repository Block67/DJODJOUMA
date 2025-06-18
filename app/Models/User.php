<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [
        'telegram_id',
        'first_name',
        'last_name',
        'username',
        'language_code',
    ];

    public function tontines()
    {
        return $this->belongsToMany(Tontine::class, 'tontine_members')
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

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}