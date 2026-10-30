<?php

use App\Game\Game;
use App\Models\CompletedRound;
use App\Models\Guess;
use App\Models\Participant;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['game.objects' => [
        ['name' => 'violão', 'aliases' => []], ['name' => 'cadeira', 'aliases' => []],
        ['name' => 'abacaxi', 'aliases' => []], ['name' => 'bicicleta', 'aliases' => []],
    ]]);
    $this->game = app(Game::class);
    $this->a = $this->game->create('Ana', 'drawer');
    $this->b = $this->game->join($this->a['state']['code'], 'Bia');
    $this->drawer = Participant::find($this->a['state']['you']['id']);
    $this->evaluator = Participant::find($this->b['state']['you']['id']);
    $this->game->act($this->evaluator, 'start', ['round_number' => 1]);
});

function finishCurrent(Game $game, Participant $participant): array
{
    $room = Room::find($participant->room_id);
    $drawer = $room->participants()->where('role', 'drawer')->first();

    return $game->act($drawer, 'guess', ['round_number' => $room->round_number, 'guess' => $room->secret]);
}
function readyBoth(Game $game, Participant $a, Participant $b, int $number): array
{
    $game->act($a, 'next', ['round_number' => $number]);

    return $game->act($b, 'next', ['round_number' => $number]);
}
function roundHeaders(array $admission): array
{
    Auth::forgetGuards();

    return ['Authorization' => 'Bearer '.$admission['token']];
}

it('waits for both confirmations and swaps roles without replacing the room or credentials', function () {
    finishCurrent($this->game, $this->drawer);
    $first = $this->game->act($this->drawer, 'next', ['round_number' => 1]);
    expect($first['state']['status'])->toBe('finished')->and($first['state']['next_ready'])->toBe([$this->drawer->id]);
    $again = $this->game->act($this->drawer, 'next', ['round_number' => 1]);
    expect($again['state']['next_ready'])->toHaveCount(1)->and($again['state']['round_number'])->toBe(1);
    $second = $this->game->act($this->evaluator, 'next', ['round_number' => 1]);
    expect($second['state']['status'])->toBe('playing')->and($second['state']['round_number'])->toBe(2)
        ->and($second['state']['you']['role'])->toBe('drawer')->and($second['state'])->not->toHaveKeys(['secret', 'proximity']);
    $this->getJson('/api/room', roundHeaders($this->a))->assertOk()->assertJsonPath('round_number', 2)->assertJsonPath('you.role', 'evaluator');
    $this->getJson('/api/room', roundHeaders($this->b))->assertOk()->assertJsonPath('round_number', 2)->assertJsonPath('you.role', 'drawer')->assertJsonMissingPath('secret');
    expect(Room::count())->toBe(1)->and(Participant::count())->toBe(2)
        ->and(Room::first()->code)->toBe($this->a['state']['code'])->and(CompletedRound::count())->toBe(1);
});

it('resets the active round but preserves its previous canvas guesses roles and score', function () {
    $op = ['round_number' => 1, 'id' => (string) Str::uuid(), 'kind' => 'stroke', 'base_revision' => 0,
        'points' => [[12, 20], [30, 45]], 'color' => '#18252b', 'width' => 5];
    $this->game->act($this->drawer, 'draw', $op);
    $this->game->act($this->evaluator, 'feedback', ['round_number' => 1, 'score' => 95]);
    $old = $this->game->state($this->evaluator);
    finishCurrent($this->game, $this->drawer);
    $this->travel(10)->seconds();
    readyBoth($this->game, $this->drawer, $this->evaluator, 1);
    $state = $this->game->state($this->drawer);
    expect($state['drawing'])->toBe([])->and($state['guesses'])->toBe([])
        ->and($state['revision'])->toBe(0)->and($state['temperature'])->toBe('muito frio')
        ->and($state['proximity'])->toBe(0)->and($state['result'])->toBeNull()->and($state['next_ready'])->toBe([])
        ->and($state['secret'])->not->toBe($old['secret'])->and(array_column($state['scores'], 'points'))->toBe([1, 1]);
    $room = Room::first();
    expect($room->started_at->diffInSeconds($room->ends_at))->toEqual(180)
        ->and($room->finished_at)->toBeNull()->and($room->point_count)->toBe(0);
    $archive = $this->getJson('/api/rounds/1', roundHeaders($this->b))->assertOk()->json();
    expect($archive['drawing'][0]['points'])->toBe($op['points'])->and($archive['proximity'])->toBe(95)
        ->and($archive['participants'][0]['role'])->toBe('drawer')->and($archive['guesses'])->toHaveCount(1)
        ->and($archive['guesses'][0]['round_number'])->toBe(1)->and($archive['secret'])->toBe($old['secret']);
    $this->getJson('/api/rounds/2', roundHeaders($this->b))->assertNotFound();
});

it('rechecks roles for old participant instances and protects the new secret', function () {
    finishCurrent($this->game, $this->drawer);
    readyBoth($this->game, $this->drawer, $this->evaluator, 1);
    // Deliberately use objects loaded before the swap.
    expect($this->evaluator->role)->toBe('evaluator');
    expect($this->game->state($this->evaluator))->not->toHaveKeys(['secret', 'proximity']);
    $this->postJson('/api/room/feedback', ['round_number' => 2, 'score' => 80], roundHeaders($this->b))->assertForbidden();
    $this->postJson('/api/room/guess', ['round_number' => 2, 'guess' => 'teste'], roundHeaders($this->a))->assertForbidden();
    $this->postJson('/api/room/feedback', ['round_number' => 2, 'score' => 80], roundHeaders($this->a))->assertOk();
    $this->postJson('/api/room/guess', ['round_number' => 2, 'guess' => 'teste'], roundHeaders($this->b))->assertOk()->assertJsonMissingPath('state.secret');
});

it('blocks all delayed actions and duplicate next requests from the previous round', function (string $action, array $payload) {
    finishCurrent($this->game, $this->drawer);
    readyBoth($this->game, $this->drawer, $this->evaluator, 1);
    $version = Room::first()->version;
    $this->postJson('/api/room/'.$action, ['round_number' => 1, ...$payload], roundHeaders($this->a))->assertConflict();
    $this->postJson('/api/room/'.$action, ['round_number' => 1, ...$payload], roundHeaders($this->b))->assertConflict();
    expect(Room::first()->round_number)->toBe(2)->and(Room::first()->version)->toBe($version)
        ->and(CompletedRound::count())->toBe(1)->and(Guess::count())->toBe(1);
})->with([
    ['next', []], ['start', []], ['feedback', ['score' => 50]], ['guess', ['guess' => 'teste']],
    ['judge', ['guess_id' => 1, 'decision' => 'accepted']],
    ['draw', ['id' => '01537e8b-b705-47ca-b81d-bbfbc08c7cda', 'kind' => 'clear', 'base_revision' => 0]],
]);

it('requires an explicit round identity on actions', function () {
    $this->postJson('/api/room/guess', ['guess' => 'teste'], roundHeaders($this->a))->assertUnprocessable()->assertJsonValidationErrors('round_number');
});

it('does not allow readiness before the round has finished', function () {
    $this->postJson('/api/room/next', ['round_number' => 1], roundHeaders($this->a))->assertUnprocessable();
    expect(Room::first()->next_ready)->toBe([]);
});

it('does not let a new evaluator judge a previous rounds pending guess', function () {
    $old = $this->game->act($this->drawer, 'guess', ['round_number' => 1, 'guess' => 'um palpite'])['guess_update'];
    finishCurrent($this->game, $this->drawer);
    readyBoth($this->game, $this->drawer, $this->evaluator, 1);
    $this->postJson('/api/room/judge', ['round_number' => 2, 'guess_id' => $old['id'], 'decision' => 'accepted'], roundHeaders($this->a))->assertNotFound();
    expect(Guess::find($old['id'])->status)->toBe('pending');
});

it('does not replay a previous drawings operation under the new round number', function () {
    $op = ['round_number' => 1, 'id' => (string) Str::uuid(), 'kind' => 'clear', 'base_revision' => 0];
    $this->game->act($this->drawer, 'draw', $op);
    finishCurrent($this->game, $this->drawer);
    readyBoth($this->game, $this->drawer, $this->evaluator, 1);
    $this->postJson('/api/room/draw', [...$op, 'round_number' => 2], roundHeaders($this->b))->assertConflict();
    expect(Room::first()->revision)->toBe(0);
});

it('accumulates cooperative points exactly once across automatic manual and timed out rounds', function () {
    finishCurrent($this->game, $this->drawer);
    readyBoth($this->game, $this->drawer, $this->evaluator, 1);
    $pending = $this->game->act($this->evaluator, 'guess', ['round_number' => 2, 'guess' => 'variação'])['guess_update'];
    $this->game->act($this->drawer, 'judge', ['round_number' => 2, 'guess_id' => $pending['id'], 'decision' => 'accepted']);
    readyBoth($this->game, $this->drawer, $this->evaluator, 2);
    $this->travel(181)->seconds();
    $this->artisan('game:expire')->assertExitCode(0);
    $this->artisan('game:expire')->assertExitCode(0);
    $state = $this->game->state($this->drawer);
    expect(array_column($state['scores'], 'points'))->toBe([2, 2])->and($state['rounds'])->toHaveCount(3)
        ->and(array_column($state['rounds'], 'result'))->toBe(['guessed', 'guessed', 'timeout'])
        ->and(CompletedRound::count())->toBe(3);
    readyBoth($this->game, $this->drawer, $this->evaluator, 3);
    expect($this->game->state($this->drawer)['round_number'])->toBe(4);
});

it('avoids the last three objects when possible and degrades safely for a small catalog', function () {
    $seen = [];
    for ($i = 1; $i <= 8; $i++) {
        $secret = Room::first()->secret;
        expect(in_array($secret, array_slice($seen, -3), true))->toBeFalse();
        $seen[] = $secret;
        finishCurrent($this->game, $this->drawer);
        readyBoth($this->game, $this->drawer, $this->evaluator, $i);
    }
    config(['game.objects' => [['name' => 'único', 'aliases' => []]]]);
    for ($i = 9; $i <= 11; $i++) {
        finishCurrent($this->game, $this->drawer);
        readyBoth($this->game, $this->drawer, $this->evaluator, $i);
        expect(Room::first()->secret)->toBe('único');
    }
});

it('protects archived rounds from outsiders and never serves an active round as history', function () {
    $this->getJson('/api/rounds/1', roundHeaders($this->a))->assertNotFound();
    finishCurrent($this->game, $this->drawer);
    Auth::forgetGuards();
    $this->getJson('/api/rounds/1')->assertUnauthorized();
    $other = $this->game->create('Outra sala', 'drawer');
    $this->getJson('/api/rounds/1', roundHeaders($other))->assertNotFound();
    $this->getJson('/api/rounds/1', roundHeaders($this->a))->assertOk()->assertJsonMissingPath('aliases')->assertJsonMissingPath('token_hash');
});
