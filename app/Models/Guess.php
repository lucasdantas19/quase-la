<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Guess extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['judged_at' => 'immutable_datetime'];
    }

    public function toHistory(): array
    {
        return [
            'id' => $this->id,
            'round_number' => $this->round_number,
            'text' => $this->text,
            'status' => $this->status,
            'judged_by' => $this->judged_by,
            'created_at' => $this->created_at->toISOString(),
            'judged_at' => $this->judged_at?->toISOString(),
        ];
    }
}
