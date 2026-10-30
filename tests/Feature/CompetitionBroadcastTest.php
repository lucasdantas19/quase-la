<?php

use App\Events\GameUpdated;
use App\Game\Game;
use App\Models\Participant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

it('sends synchronized starts privately and keeps subsequent drawing and guess deltas inside each team', function () {
    Event::fake([GameUpdated::class]);
    $game = app(Game::class);
    $a = $game->create('Ana', 'drawer', true);
    $b = $game->join($a['state']['code'], 'Bia');
    $c = $game->create('Caio', 'drawer', false, $a['state']['competition']['code']);
    $d = $game->join($c['state']['code'], 'Dani');
    $players = collect([$a, $b, $c, $d])->map(fn ($a) => Participant::findOrFail($a['state']['you']['id']));
    $game->act($players[0], 'start', ['round_number' => 1]);
    foreach ($players as $player) {
        Event::assertDispatched(GameUpdated::class, function ($event) use ($player) {
            if ($event->participantId !== $player->id || $event->payload['state']['status'] !== 'playing') {
                return false;
            }
            if ($player->role === 'drawer') {
                expect($event->payload['state'])->not->toHaveKeys(['secret', 'proximity', 'aliases']);
            }

            return true;
        });
    }
    Event::fake([GameUpdated::class]);
    $game->act($players[0], 'draw', ['round_number' => 1, 'id' => (string) Str::uuid(), 'base_revision' => 0, 'kind' => 'stroke', 'points' => [[12, 24]], 'color' => '#18252b', 'width' => 5]);
    $game->act($players[0], 'guess', ['round_number' => 1, 'guess' => 'palpite privado']);
    Event::assertNotDispatched(GameUpdated::class, fn ($event) => in_array($event->participantId, [$players[2]->id, $players[3]->id]));
    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->participantId === $players[1]->id && ($event->payload['delta']['chunk']['points'] ?? null) === [[12, 24]]);
});

it('delivers the frozen final ranking to every participant without sharing opponent snapshots', function () {
    $game = app(Game::class);
    Event::fake([GameUpdated::class]);
    $a = $game->create('Ana', 'drawer', true);
    $b = $game->join($a['state']['code'], 'Bia');
    $c = $game->create('Caio', 'drawer', false, $a['state']['competition']['code']);
    $d = $game->join($c['state']['code'], 'Dani');
    $players = collect([$a, $b, $c, $d])->map(fn ($a) => Participant::findOrFail($a['state']['you']['id']));
    $game->act($players[0], 'start', ['round_number' => 1]);
    $game->act($players[0], 'guess', ['round_number' => 1, 'guess' => 'palpite privado']);
    Event::fake([GameUpdated::class]);
    $game->manageCompetition($players[0], 'end', ['round_number' => 1]);
    foreach ($players as $index => $player) {
        Event::assertDispatched(GameUpdated::class, function ($event) use ($player, $index) {
            if ($event->participantId !== $player->id) {
                return false;
            }
            $state = $event->payload['state'];
            expect($state['status'])->toBe('closed')
                ->and($state['competition']['ended_at'])->not->toBeNull()
                ->and($state['competition']['is_organizer'])->toBe($index === 0)
                ->and($state['guesses'])->toHaveCount($index < 2 ? 1 : 0);
            foreach ($state['competition']['teams'] as $team) {
                expect($team)->not->toHaveKeys(['secret', 'drawing', 'guesses', 'code']);
            }

            return true;
        });
    }
});

it('broadcasts the shortened deadline to opponents without leaking the winning guess or object', function () {
    Event::fake([GameUpdated::class]);
    $game = app(Game::class);
    $a = $game->create('Ana', 'drawer', true);
    $b = $game->join($a['state']['code'], 'Bia');
    $c = $game->create('Caio', 'drawer', false, $a['state']['competition']['code']);
    $d = $game->join($c['state']['code'], 'Dani');
    $players = collect([$a, $b, $c, $d])->map(fn ($a) => Participant::findOrFail($a['state']['you']['id']));
    $game->act($players[0], 'start', ['round_number' => 1]);
    $secret = $game->state($players[1])['secret'];
    Event::fake([GameUpdated::class]);
    $game->act($players[0], 'guess', ['round_number' => 1, 'guess' => $secret]);
    foreach ([2, 3] as $index) {
        Event::assertDispatched(GameUpdated::class, function ($event) use ($players, $index) {
            if ($event->participantId !== $players[$index]->id) {
                return false;
            }
            $state = $event->payload['state'];
            expect($state['final_window_started_at'])->not->toBeNull()
                ->and(strtotime($state['ends_at']) - strtotime($state['final_window_started_at']))->toBeLessThanOrEqual(30)
                ->and($event->payload['guess_update'])->toBeNull()->and($event->payload['delta'])->toBeNull();
            if ($index === 2) {
                expect($state)->not->toHaveKeys(['secret', 'proximity', 'aliases', 'guesses', 'drawing']);
            }

            return true;
        });
    }
});
