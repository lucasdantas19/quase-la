<?php

use App\Game\Game;
use App\Models\Competition;
use App\Models\CompletedRound;
use App\Models\Participant;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->game = app(Game::class);
    $this->a = $this->game->create('Ana', 'drawer', true);
    $this->b = $this->game->join($this->a['state']['code'], 'Bia');
    $this->c = $this->game->create('Caio', 'drawer', false, $this->a['state']['competition']['code']);
    $this->d = $this->game->join($this->c['state']['code'], 'Dani');
    $this->players = collect([$this->a, $this->b, $this->c, $this->d])->map(fn ($a) => Participant::findOrFail($a['state']['you']['id']));
    $this->competition = Competition::first();
});

function managementHeaders(array $admission): array
{
    Auth::forgetGuards();

    return ['Authorization' => 'Bearer '.$admission['token']];
}

it('grants organizer controls only to the creator regardless of the drawing role', function () {
    expect($this->a['state']['competition']['is_organizer'])->toBeTrue()
        ->and($this->b['state']['competition']['is_organizer'])->toBeFalse();
    $this->postJson('/api/competition/end', ['round_number' => 1], managementHeaders($this->b))->assertForbidden();
    $this->postJson('/api/competition/remove', ['round_number' => 1, 'room_id' => $this->c['state']['id']], managementHeaders($this->d))->assertForbidden();
    $this->postJson('/api/room/start', ['round_number' => 1], managementHeaders($this->b))->assertForbidden();
    $this->postJson('/api/room/start', ['round_number' => 1], managementHeaders($this->a))->assertOk()->assertJsonPath('state.status', 'playing');
});

it('removes only incomplete opponent teams in the same lobby and releases capacity', function () {
    $pending = $this->game->create('Eva', 'drawer', false, $this->competition->code);
    $this->postJson('/api/competition/remove', ['round_number' => 1], managementHeaders($this->a))->assertUnprocessable();
    $this->postJson('/api/competition/remove', ['round_number' => 1, 'room_id' => $this->c['state']['id']], managementHeaders($this->a))->assertUnprocessable();
    $foreign = $this->game->create('Fora', 'drawer');
    $this->postJson('/api/competition/remove', ['round_number' => 1, 'room_id' => $foreign['state']['id']], managementHeaders($this->a))->assertNotFound();
    $this->postJson('/api/competition/remove', ['round_number' => 1, 'room_id' => $pending['state']['id']], managementHeaders($this->a))->assertOk();
    $this->getJson('/api/room', managementHeaders($pending))->assertOk()->assertJsonPath('close_reason', 'removed')->assertJsonPath('status', 'closed');
    expect($this->game->state($this->players[0])['competition']['teams'])->toHaveCount(2);
    expect(fn () => $this->game->join($pending['state']['code'], 'Tarde'))->toThrow(ValidationException::class);
    for ($i = 0; $i < 6; $i++) {
        $this->game->create('Dupla '.$i, 'drawer', false, $this->competition->code);
    }
    expect($this->competition->rooms()->whereNull('closed_at')->count())->toBe(8);
    expect(fn () => $this->game->create('Nona', 'drawer', false, $this->competition->code))->toThrow(ValidationException::class);
});

it('ends atomically preserving wins and freezes the final ranking across later departures', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $this->game->act($this->players[2], 'guess', ['round_number' => 1, 'guess' => Room::first()->secret]);
    $ended = $this->game->manageCompetition($this->players[0], 'end', ['round_number' => 1]);
    expect($ended['competition']['ended_at'])->not->toBeNull()
        ->and($ended['close_reason'])->toBe('competition_ended')
        ->and(array_column($ended['competition']['teams'], 'points'))->toBe([2, 0])
        ->and(Room::pluck('status')->all())->toBe(['closed', 'closed'])
        ->and(CompletedRound::count())->toBe(2)
        ->and(CompletedRound::where('room_id', $this->players[0]->room_id)->first()->result)->toBe('abandoned');
    $again = $this->game->manageCompetition($this->players[0], 'end', ['round_number' => 1]);
    expect($again['competition'])->toBe($ended['competition'])->and(CompletedRound::count())->toBe(2);
    $this->game->leave($this->players[2]);
    expect($this->game->state($this->players[1])['competition']['teams'])->toBe($ended['competition']['teams']);
    $this->postJson('/api/room/next', ['round_number' => 1], managementHeaders($this->a))->assertUnprocessable();
    expect(fn () => $this->game->create('Tarde', 'drawer', false, $this->competition->code))->toThrow(ValidationException::class);
});

it('can cancel a lobby without fabricating rounds and blocks all later admissions', function () {
    $ended = $this->game->manageCompetition($this->players[0], 'end', ['round_number' => 1]);
    expect($ended['competition']['ended_at'])->not->toBeNull()->and(CompletedRound::count())->toBe(0);
    expect(fn () => $this->game->join($this->a['state']['code'], 'Outra'))->toThrow(ValidationException::class);
    $this->postJson('/api/competition/remove', ['round_number' => 1, 'room_id' => $this->c['state']['id']], managementHeaders($this->a))->assertUnprocessable();
});

it('gives the deadline priority over manual termination and rejects stale management commands', function () {
    $this->game->act($this->players[0], 'start', ['round_number' => 1]);
    $this->postJson('/api/competition/end', ['round_number' => 99], managementHeaders($this->a))->assertConflict();
    $this->travel(180)->seconds();
    $this->game->manageCompetition($this->players[0], 'end', ['round_number' => 1]);
    expect(CompletedRound::pluck('result')->all())->toBe(['timeout', 'timeout']);
});

it('transfers control when the organizer or their partner closes the room', function () {
    $this->game->leave($this->players[1]);
    expect($this->competition->fresh()->organizer_id)->toBe($this->players[2]->id)
        ->and($this->game->state($this->players[2])['competition']['is_organizer'])->toBeTrue();
    $this->postJson('/api/competition/end', ['round_number' => 1], managementHeaders($this->a))->assertForbidden();
    $this->game->manageCompetition($this->players[2], 'end', ['round_number' => 1]);
    expect($this->competition->fresh()->ended_by)->toBe($this->players[2]->id);
});

it('leaves independent room rules unchanged and refuses competition controls there', function () {
    $solo = $this->game->create('Solo', 'drawer');
    $this->postJson('/api/competition/end', ['round_number' => 1], managementHeaders($solo))->assertNotFound();
});

it('backfills the oldest active organizer for existing competitions without changing game data', function () {
    $migration = require database_path('migrations/2026_10_29_000005_add_competition_organizer.php');
    $migration->down();
    $migration->up();
    expect($this->competition->fresh()->organizer_id)->toBe($this->players[0]->id)
        ->and(Room::count())->toBe(2)->and(Participant::count())->toBe(4);
});
