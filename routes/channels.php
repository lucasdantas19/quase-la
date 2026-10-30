<?php

use App\Models\Participant;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('participants.{id}', fn (Participant $p, string $id) => (string) $p->id === $id, ['guards' => ['participant']]);
