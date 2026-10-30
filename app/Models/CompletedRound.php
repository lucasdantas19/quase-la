<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompletedRound extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'finished_at' => 'immutable_datetime'];
    }

    public function summary(): array
    {
        return ['number' => $this->number, 'secret' => $this->secret, 'result' => $this->result,
            'finished_at' => $this->finished_at->toISOString(), 'points_each' => $this->result === 'guessed' ? 1 : 0];
    }
}
