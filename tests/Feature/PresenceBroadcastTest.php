<?php

use App\Events\GameUpdated;
use App\Game\Game;
use App\Models\Participant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;

uses(DatabaseMigrations::class);

it('broadcasts absence recovery and departure while excluding revoked participants', function () {
    Event::fake([GameUpdated::class]);
    $game = app(Game::class);
    $a = $game->create('Ana', 'drawer');
    $b = $game->join($a['state']['code'], 'Bia');
    $drawer = Participant::find($a['state']['you']['id']);
    $evaluator = Participant::find($b['state']['you']['id']);
    $game->act($evaluator, 'start', ['round_number' => 1]);
    $this->travel(21)->seconds();
    $game->state($drawer);
    Event::assertDispatched(GameUpdated::class, function ($event) use ($drawer, $evaluator) {
        if ($event->participantId !== $drawer->id) {
            return false;
        }
        $partner = collect($event->payload['state']['participants'])->firstWhere('id', $evaluator->id);
        if ($partner['is_online'] !== false) {
            return false;
        }
        expect($event->payload['state'])->not->toHaveKeys(['secret', 'proximity']);

        return true;
    });
    $offlineVersion = $game->state($drawer)['version'];
    $game->state($evaluator);
    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->participantId === $drawer->id && $event->payload['state']['version'] > $offlineVersion && collect($event->payload['state']['participants'])->firstWhere('id', $evaluator->id)['is_online'] === true);
    $game->leave($evaluator);
    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->participantId === $drawer->id && $event->payload['state']['status'] === 'closed' && $event->payload['state']['result'] === 'abandoned');
    Event::assertNotDispatched(GameUpdated::class, fn ($event) => $event->participantId === $evaluator->id && $event->payload['state']['status'] === 'closed');
});
