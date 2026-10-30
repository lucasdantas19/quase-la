<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Participant extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime', 'left_at' => 'immutable_datetime', 'is_online' => 'boolean'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
