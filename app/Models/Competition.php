<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Competition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ended_at' => 'immutable_datetime', 'final_standings' => 'array'];
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
