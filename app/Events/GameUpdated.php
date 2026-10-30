<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class GameUpdated implements ShouldBroadcastNow
{
    public function __construct(public int $participantId, public array $payload) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('participants.'.$this->participantId)];
    }

    public function broadcastAs(): string
    {
        return 'game.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
