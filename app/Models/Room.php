<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret', 'aliases'];

    protected function casts(): array
    {
        return ['final_window_started_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'next_ready' => 'array', 'round_number' => 'integer', 'drawing' => 'array', 'aliases' => 'array', 'started_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function completedRounds(): HasMany
    {
        return $this->hasMany(CompletedRound::class)->orderBy('number');
    }

    public function guesses(): HasMany
    {
        return $this->hasMany(Guess::class)->where('round_number', $this->round_number)->orderBy('id');
    }
}
