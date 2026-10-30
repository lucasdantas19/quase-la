<?php

use App\Game\Game;
use App\Models\Participant;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->startOfSecond());
    $this->game = app(Game::class);
    $a = $this->game->create('Ana', 'drawer', true);
    $b = $this->game->join($a['state']['code'], 'Bia');
    $c = $this->game->create('Caio', 'drawer', false, $a['state']['competition']['code']);
    $d = $this->game->join($c['state']['code'], 'Dani');
    $this->code = $a['state']['competition']['code'];
    $this->players = collect([$a, $b, $c, $d])->map(fn ($a) => Participant::findOrFail($a['state']['you']['id']));
});

function finalWindowWin(Game $game, Participant $drawer): array
{
    return $game->act($drawer, 'guess', ['round_number' => 1, 'guess' => $drawer->room()->first()->secret]);
}

it('shortens the remaining teams deadline to thirty seconds and restores it on reconnect', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $this->travel(10)->seconds();
    $won = finalWindowWin($this->game, $this->players[0]);
    $expected = now()->addSeconds(30)->toISOString();
    $remaining = $this->game->state($this->players[2]);
    expect($remaining['ends_at'])->toBe($expected)->and($won['state']['ends_at'])->toBe($expected)
        ->and($remaining['final_window_started_at'])->toBe(now()->toISOString())
        ->and($remaining['status'])->toBe('playing')->and($remaining)->not->toHaveKeys(['secret', 'proximity']);
    $this->travel(7)->seconds();
    expect($this->game->state($this->players[2])['ends_at'])->toBe($expected);
});

it('triggers on human acceptance but never on pending or rejected guesses', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $first = $this->game->act($this->players[0], 'guess', ['round_number' => 1, 'guess' => 'variação humana'])['guess_update'];
    expect($this->game->state($this->players[2])['final_window_started_at'])->toBeNull();
    $this->game->act($this->players[1], 'judge', ['round_number' => 1, 'guess_id' => $first['id'], 'decision' => 'rejected']);
    expect($this->game->state($this->players[2])['final_window_started_at'])->toBeNull();
    $second = $this->game->act($this->players[0], 'guess', ['round_number' => 1, 'guess' => 'outro sinônimo'])['guess_update'];
    $this->travel(5)->seconds();
    $this->game->act($this->players[1], 'judge', ['round_number' => 1, 'guess_id' => $second['id'], 'decision' => 'accepted']);
    expect($this->game->state($this->players[2])['ends_at'])->toBe(now()->addSeconds(30)->toISOString());
});

it('never extends the original round when the first win happens in its last thirty seconds', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $original = Room::first()->ends_at->toISOString();
    $this->travel(170)->seconds();
    finalWindowWin($this->game, $this->players[0]);
    expect($this->game->state($this->players[2])['ends_at'])->toBe($original);
    $this->travel(10)->seconds();
    $this->game->expireDue();
    expect($this->game->state($this->players[2])['result'])->toBe('timeout');
});

it('rejects guesses at the new deadline even though the original round still has time', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    finalWindowWin($this->game, $this->players[0]);
    $this->travel(30)->seconds();
    expect(fn () => finalWindowWin($this->game, $this->players[2]))->toThrow(ValidationException::class);
    $state = $this->game->state($this->players[2]);
    expect($state['result'])->toBe('timeout')->and($state['guesses'])->toBe([])
        ->and(array_column($state['competition']['teams'], 'points'))->toBe([2, 0]);
});

it('rejects late human decisions and expires without any connected browser', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $guess = $this->game->act($this->players[2], 'guess', ['round_number' => 1, 'guess' => 'sinônimo pendente'])['guess_update'];
    finalWindowWin($this->game, $this->players[0]);
    $this->travel(30)->seconds();
    $this->game->expireDue();
    expect(fn () => $this->game->act($this->players[3], 'judge', ['round_number' => 1, 'guess_id' => $guess['id'], 'decision' => 'accepted']))->toThrow(ValidationException::class);
    expect($this->game->state($this->players[2])['result'])->toBe('timeout')
        ->and($this->game->state($this->players[0])['result'])->toBe('guessed');
});

it('does not renew the window after another team wins', function () {
    $e = $this->game->create('Eva', 'drawer', false, $this->code);
    $this->game->join($e['state']['code'], 'Felipe');
    $third = Participant::findOrFail($e['state']['you']['id']);
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    finalWindowWin($this->game, $this->players[0]);
    $deadline = now()->addSeconds(30)->toISOString();
    $this->travel(15)->seconds();
    finalWindowWin($this->game, $this->players[2]);
    expect($this->game->state($third)['ends_at'])->toBe($deadline);
});

it('accepts a win just before the new deadline and resets the window for the next round', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    finalWindowWin($this->game, $this->players[0]);
    $this->travel(29)->seconds();
    expect(finalWindowWin($this->game, $this->players[2])['correct'])->toBeTrue();
    foreach ($this->players as $player) {
        $this->game->state($player);
    }
    foreach ($this->players as $player) {
        $this->game->act($player, 'next', ['round_number' => 1]);
    }
    foreach ($this->players as $player) {
        $state = $this->game->state($player);
        expect($state['round_number'])->toBe(2)->and($state['final_window_started_at'])->toBeNull()
            ->and($state['ends_at'])->toBe(now()->addSeconds(180)->toISOString());
    }
});

it('does not create a final window for independent rooms', function () {
    $a = $this->game->create('Solo', 'drawer');
    $b = $this->game->join($a['state']['code'], 'Par');
    $drawer = Participant::findOrFail($a['state']['you']['id']);
    $this->game->act(Participant::findOrFail($b['state']['you']['id']), 'start', ['round_number' => 1]);
    expect(finalWindowWin($this->game, $drawer)['state']['final_window_started_at'])->toBeNull();
});
