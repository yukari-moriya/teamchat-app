<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        // 写しの列（docs/design/data.md 2-1）は、保存のたびに元の列から作る
        static::saving(function (User $user) {
            $user->name_normalized = TextNormalizer::normalize($user->name);
            $user->email_normalized = TextNormalizer::normalize($user->email);
        });
    }
}
