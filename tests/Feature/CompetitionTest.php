<?php

use App\Game\Game;
use App\Models\Participant;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = app(Game::class);
    $this->a = $this->game->create('Ana', 'drawer', true);
    $this->b = $this->game->join($this->a['state']['code'], 'Bia');
    $code = $this->a['state']['competition']['code'];
    $this->c = $this->game->create('Caio', 'drawer', false, $code);
    $this->d = $this->game->join($this->c['state']['code'], 'Dani');
    $this->players = collect([$this->a, $this->b, $this->c, $this->d])->map(fn ($a) => Participant::findOrFail($a['state']['you']['id']));
});

it('starts all teams together with the same secret and blocks late admission', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $rooms = Room::orderBy('id')->get();
    expect($rooms->pluck('status')->all())->toBe(['playing', 'playing'])
        ->and($rooms[0]->secret)->toBe($rooms[1]->secret)
        ->and($rooms[0]->ends_at->toISOString())->toBe($rooms[1]->ends_at->toISOString());
    $state = $this->game->state($this->players[0]);
    expect($state)->not->toHaveKey('secret');
    expect($state['competition']['teams'][1])->not->toHaveKeys(['secret', 'drawing', 'guesses', 'code']);
    expect(fn () => $this->game->create('Late', 'drawer', false, $state['competition']['code']))->toThrow(ValidationException::class);
});

it('requires complete connected teams and organizer authority', function () {
    $this->players[3]->update(['last_seen_at' => now()->subMinute(), 'is_online' => false]);
    expect(fn () => $this->game->act($this->players[0], 'start', ['round_number' => 1]))->toThrow(ValidationException::class);
    expect(Room::where('status', 'playing')->count())->toBe(0);
    $this->postJson('/api/room/start', ['round_number' => 1], ['Authorization' => 'Bearer '.$this->b['token']])->assertForbidden();
});

it('awards ranking points in accepted order including same-second wins and synchronizes subsequent rounds', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $secret = Room::first()->secret;
    // The second room wins first: timestamps may be identical, archive order must decide.
    $this->game->act($this->players[2], 'guess', ['round_number' => 1, 'guess' => $secret]);
    $this->game->act($this->players[0], 'guess', ['round_number' => 1, 'guess' => $secret]);
    $state = $this->game->state($this->players[0]);
    expect(array_column($state['competition']['teams'], 'points'))->toBe([2, 1])
        ->and($state['competition']['teams'][0]['id'])->toBe($this->players[2]->room_id);
    foreach ($this->players->take(3) as $player) {
        $this->game->act($player, 'next', ['round_number' => 1]);
    }
    expect(Room::pluck('round_number')->all())->toBe([1, 1]);
    $this->game->act($this->players[3], 'next', ['round_number' => 1]);
    expect(Room::pluck('round_number')->all())->toBe([2, 2]);
    expect(Room::pluck('secret')->unique())->toHaveCount(1);
    expect($this->game->state($this->players[0])['you']['role'])->toBe('evaluator');
    expect($this->game->state($this->players[1]))->not->toHaveKey('secret');
});

it('keeps opponent guesses drawings and archives private and leaves other teams running on departure', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $this->game->act($this->players[2], 'guess', ['round_number' => 1, 'guess' => 'palpite privado']);
    expect($this->game->state($this->players[0])['guesses'])->toBe([]);
    $this->game->leave($this->players[2]);
    expect(Room::find($this->players[0]->room_id)->status)->toBe('playing');
    $this->getJson('/api/rounds/1', ['Authorization' => 'Bearer '.$this->a['token']])->assertNotFound();
    $this->game->act($this->players[0], 'guess', ['round_number' => 1, 'guess' => Room::first()->secret]);
    $this->game->act($this->players[0], 'next', ['round_number' => 1]);
    $this->game->act($this->players[1], 'next', ['round_number' => 1]);
    expect(Room::find($this->players[0]->room_id)->round_number)->toBe(2);
});

it('waits for unfinished opponents and gives zero points to expired teams', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $this->game->act($this->players[0], 'guess', ['round_number' => 1, 'guess' => Room::first()->secret]);
    $this->game->act($this->players[0], 'next', ['round_number' => 1]);
    $this->game->act($this->players[1], 'next', ['round_number' => 1]);
    expect(Room::pluck('round_number')->all())->toBe([1, 1]);
    $this->travel(181)->seconds();
    $this->game->expireDue();
    $state = $this->game->state($this->players[2]);
    expect($state['result'])->toBe('timeout')
        ->and(array_column($state['competition']['teams'], 'points'))->toBe([2, 0]);
    // Presence expiry invalidates readiness, requiring fresh confirmation from everyone.
    foreach ($this->players as $player) {
        $this->game->state($player);
    }
    foreach ($this->players as $player) {
        $this->game->act($player, 'next', ['round_number' => 1]);
    }
    expect(Room::pluck('round_number')->all())->toBe([2, 2]);
});

it('ranks human acceptance without counting a repeated confirmation twice', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $guess = $this->game->act($this->players[2], 'guess', ['round_number' => 1, 'guess' => 'sinônimo humano'])['guess_update'];
    $this->game->act($this->players[3], 'judge', ['round_number' => 1, 'guess_id' => $guess['id'], 'decision' => 'accepted']);
    $this->game->act($this->players[2], 'next', ['round_number' => 1]);
    $this->game->act($this->players[2], 'next', ['round_number' => 1]);
    expect($this->game->state($this->players[0])['competition']['teams'][0]['points'])->toBe(2);
});

it('advances ready opponents when the last unready team leaves', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $this->game->act($this->players[0], 'guess', ['round_number' => 1, 'guess' => Room::first()->secret]);
    $this->game->act($this->players[0], 'next', ['round_number' => 1]);
    $this->game->act($this->players[1], 'next', ['round_number' => 1]);
    $this->game->leave($this->players[2]);
    expect(Room::find($this->players[0]->room_id)->round_number)->toBe(2)
        ->and(Room::find($this->players[2]->room_id)->status)->toBe('closed');
});
