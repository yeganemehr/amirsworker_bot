<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Upload extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'source_url',
        'disk',
        'path',
        'public_url',
        'size_bytes',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'size_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
