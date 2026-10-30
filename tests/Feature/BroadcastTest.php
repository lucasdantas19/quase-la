<?php

use App\Events\GameUpdated;
use App\Game\Game;
use App\Models\Participant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);
it('broadcasts committed changes to each authorized recipient with filtered payloads', function () {
    Event::fake([GameUpdated::class]);
    config(['game.objects' => [['name' => 'violão', 'aliases' => ['guitarra acústica']]]]);
    $game = app(Game::class);
    $drawer = $game->create('Ana', 'drawer');
    $evaluator = $game->join($drawer['state']['code'], 'Bia');
    $d = Participant::find($drawer['state']['you']['id']);
    $e = Participant::find($evaluator['state']['you']['id']);
    $game->act($e, 'start', ['round_number' => 1, ...[]]);
    $game->act($e, 'feedback', ['round_number' => 1, ...['score' => 88]]);
    Event::assertDispatched(GameUpdated::class, function ($event) use ($d) {
        $payload = $event->broadcastWith();
        $state = $payload['state'];
        if ($event->participantId !== $d->id || $state['status'] !== 'playing') {
            return false;
        }
        expect($state)->not->toHaveKeys(['secret', 'aliases', 'proximity', 'drawing'])
            ->and($event->broadcastOn()[0]->name)->toBe('private-participants.'.$d->id);

        return $state['temperature'] === 'muito quente';
    });
    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->participantId === $e->id && ($event->payload['state']['secret'] ?? null) === 'violão' && ($event->payload['state']['proximity'] ?? null) === 88);
    $operation = ['id' => (string) Str::uuid(), 'base_revision' => 0, 'kind' => 'stroke', 'points' => [[10, 20], [20, 30]], 'color' => '#18252b', 'width' => 5];
    $game->act($d, 'draw', ['round_number' => 1, ...$operation]);
    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->participantId === $e->id && ($event->payload['delta']['chunk']['points'] ?? null) === $operation['points']);
    $pending = $game->act($d, 'guess', ['round_number' => 1, ...['guess' => 'guitarra']])['guess_update'];
    foreach ([$d, $e] as $participant) {
        Event::assertDispatched(GameUpdated::class, function ($event) use ($participant, $pending) {
            if ($event->participantId !== $participant->id || ($event->payload['guess_update']['id'] ?? null) !== $pending['id']) {
                return false;
            }
            expect($event->payload['state'])->not->toHaveKey('guesses');
            if ($participant->role === 'drawer') {
                expect($event->payload['state'])->not->toHaveKeys(['secret', 'aliases', 'proximity']);
            }

            return $event->payload['guess_update']['status'] === 'pending';
        });
    }
    $game->act($e, 'judge', ['round_number' => 1, ...['guess_id' => $pending['id'], 'decision' => 'rejected']]);
    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->participantId === $d->id && ($event->payload['guess_update']['status'] ?? null) === 'rejected' && $event->payload['state']['status'] === 'playing');
    $game->act($d, 'guess', ['round_number' => 1, ...['guess' => 'violao']]);
    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->participantId === $d->id && $event->payload['state']['status'] === 'finished' && $event->payload['state']['secret'] === 'violão');
});

it('broadcasts the new round using the new roles without exposing its object to the former evaluator', function () {
    Event::fake([GameUpdated::class]);
    $game = app(Game::class);
    $a = $game->create('Ana', 'drawer');
    $b = $game->join($a['state']['code'], 'Bia');
    $drawer = Participant::find($a['state']['you']['id']);
    $evaluator = Participant::find($b['state']['you']['id']);
    $game->act($evaluator, 'start', ['round_number' => 1]);
    $secret = $game->state($evaluator)['secret'];
    $game->act($drawer, 'guess', ['round_number' => 1, 'guess' => $secret]);
    $game->act($drawer, 'next', ['round_number' => 1]);
    $game->act($evaluator, 'next', ['round_number' => 1]);
    Event::assertDispatched(GameUpdated::class, function ($event) use ($evaluator) {
        $state = $event->payload['state'];
        if ($event->participantId !== $evaluator->id || $state['round_number'] !== 2) {
            return false;
        }
        expect($state['you']['role'])->toBe('drawer')->and($state)->not->toHaveKeys(['secret', 'proximity', 'guesses', 'drawing']);

        return $state['status'] === 'playing' && $state['revision'] === 0 && $state['temperature'] === 'muito frio';
    });
    Event::assertDispatched(GameUpdated::class, fn ($event) => $event->participantId === $drawer->id && $event->payload['state']['round_number'] === 2 && $event->payload['state']['you']['role'] === 'evaluator' && isset($event->payload['state']['secret']));
});
