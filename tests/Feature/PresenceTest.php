<?php

use App\Game\Game;
use App\Models\CompletedRound;
use App\Models\Participant;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['game.objects' => [['name' => 'violão', 'aliases' => []]], 'game.presence_timeout_seconds' => 20]);
    $this->game = app(Game::class);
    $this->a = $this->game->create('Ana', 'drawer');
    $this->b = $this->game->join($this->a['state']['code'], 'Bia');
    $this->drawer = Participant::find($this->a['state']['you']['id']);
    $this->evaluator = Participant::find($this->b['state']['you']['id']);
});
function presenceHeaders(array $session): array
{
    Auth::forgetGuards();

    return ['Authorization' => 'Bearer '.$session['token']];
}
function playPresenceRound($test): void
{
    $test->game->act($test->evaluator, 'start', ['round_number' => 1]);
}

it('detects lost heartbeats reserves seats and recovers the same role without pausing the timer', function () {
    playPresenceRound($this);
    $this->game->act($this->drawer, 'guess', ['round_number' => 1, 'guess' => 'outro']);
    $this->game->act($this->drawer, 'draw', ['round_number' => 1, 'id' => (string) Str::uuid(), 'kind' => 'stroke', 'base_revision' => 0, 'points' => [[10, 20], [30, 40]], 'width' => 5, 'color' => '#18252b']);
    $deadline = Room::first()->ends_at;
    $this->travel(19)->seconds();
    $this->game->state($this->drawer);
    $this->game->markDisconnected();
    expect($this->evaluator->fresh()->is_online)->toBeTrue();
    $this->travel(2)->seconds();
    $state = $this->game->state($this->drawer);
    expect(collect($state['participants'])->firstWhere('id', $this->evaluator->id)['is_online'])->toBeFalse()
        ->and(Room::first()->ends_at->equalTo($deadline))->toBeTrue()->and(Room::first()->status)->toBe('playing');
    $this->postJson('/api/rooms/join', ['name' => 'intruso', 'code' => $state['code']])->assertUnprocessable();
    $this->getJson('/api/room', presenceHeaders($this->b))->assertOk()->assertJsonPath('you.role', 'evaluator')
        ->assertJsonPath('round_number', 1)->assertJsonPath('secret', 'violão')->assertJsonCount(1, 'drawing')->assertJsonCount(1, 'guesses');
    expect($this->evaluator->fresh()->is_online)->toBeTrue()->and(Participant::count())->toBe(2);
    $this->getJson('/api/room', presenceHeaders($this->a))->assertOk()->assertJsonMissingPath('secret')->assertJsonMissingPath('proximity');
});

it('marks absent players offline at the cutoff even when neither browser is open', function () {
    $this->travel(20)->seconds();
    $this->artisan('game:expire')->assertExitCode(0);
    expect($this->drawer->fresh()->is_online)->toBeFalse()->and($this->evaluator->fresh()->is_online)->toBeFalse()
        ->and(Room::first()->status)->toBe('waiting');
    $version = Room::first()->version;
    $this->game->markDisconnected();
    expect(Room::first()->version)->toBe($version);
});

it('requires the partner to reconnect before starting', function () {
    $this->travel(21)->seconds();
    $this->postJson('/api/room/start', ['round_number' => 1], presenceHeaders($this->b))->assertUnprocessable();
    expect(Room::first()->status)->toBe('waiting');
    $this->game->state($this->drawer);
    $this->postJson('/api/room/start', ['round_number' => 1], presenceHeaders($this->b))->assertOk();
});

it('clears an absent players next-round confirmation and restores the current round on return', function () {
    playPresenceRound($this);
    $this->game->act($this->drawer, 'guess', ['round_number' => 1, 'guess' => 'violao']);
    $this->game->act($this->drawer, 'next', ['round_number' => 1]);
    $this->travel(21)->seconds();
    $this->game->act($this->evaluator, 'next', ['round_number' => 1]);
    expect(Room::first()->round_number)->toBe(1)->and(Room::first()->next_ready)->toBe([$this->evaluator->id]);
    $this->getJson('/api/room', presenceHeaders($this->a))->assertOk()->assertJsonPath('round_number', 1)->assertJsonPath('status', 'finished');
    $this->postJson('/api/room/next', ['round_number' => 1], presenceHeaders($this->a))->assertOk()->assertJsonPath('state.round_number', 2)->assertJsonPath('state.you.role', 'evaluator');
    $this->getJson('/api/room', presenceHeaders($this->b))->assertOk()->assertJsonPath('you.role', 'drawer')->assertJsonMissingPath('secret');
});

it('still expires the round when a participant has disconnected', function () {
    playPresenceRound($this);
    $this->travel(181)->seconds();
    $this->artisan('game:expire')->assertExitCode(0);
    $this->getJson('/api/room', presenceHeaders($this->a))->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('result', 'timeout');
    expect(CompletedRound::count())->toBe(1)->and(CompletedRound::first()->result)->toBe('timeout');
});

it('closes a waiting room and revokes the departing participants credential without inventing a round', function () {
    $this->postJson('/api/room/leave', [], presenceHeaders($this->a))->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('closed_by', $this->drawer->id);
    expect($this->drawer->fresh()->left_at)->not->toBeNull()->and(CompletedRound::count())->toBe(0);
    $this->getJson('/api/room', presenceHeaders($this->a))->assertUnauthorized();
    $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-participants.'.$this->drawer->id], presenceHeaders($this->a))->assertUnauthorized();
    $this->postJson('/api/room/leave', [], presenceHeaders($this->a))->assertUnauthorized();
    $this->postJson('/api/rooms/join', ['name' => 'outro', 'code' => $this->a['state']['code']])->assertUnprocessable();
    $this->getJson('/api/room', presenceHeaders($this->b))->assertOk()->assertJsonPath('status', 'closed');
});

it('archives an abandoned active round with no points and preserves all records', function () {
    playPresenceRound($this);
    $this->game->act($this->drawer, 'guess', ['round_number' => 1, 'guess' => 'um palpite']);
    $this->postJson('/api/room/leave', [], presenceHeaders($this->b))->assertOk()->assertJsonPath('result', 'abandoned');
    $state = $this->getJson('/api/room', presenceHeaders($this->a))->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('secret', 'violão')->json();
    expect(array_column($state['scores'], 'points'))->toBe([0, 0])->and($state['guesses'])->toHaveCount(1)
        ->and(CompletedRound::count())->toBe(1)->and(CompletedRound::first()->result)->toBe('abandoned');
    $this->getJson('/api/rounds/1', presenceHeaders($this->a))->assertOk()->assertJsonPath('result', 'abandoned')->assertJsonCount(1, 'guesses');
    $this->postJson('/api/room/next', ['round_number' => 1], presenceHeaders($this->a))->assertUnprocessable();
    $this->postJson('/api/room/guess', ['round_number' => 1, 'guess' => 'violao'], presenceHeaders($this->a))->assertUnprocessable();
});

it('keeps already won points when someone leaves after the result', function () {
    playPresenceRound($this);
    $this->game->act($this->drawer, 'guess', ['round_number' => 1, 'guess' => 'violao']);
    $this->game->leave($this->evaluator);
    $state = $this->game->state($this->drawer);
    expect($state['status'])->toBe('closed')->and($state['result'])->toBe('guessed')
        ->and(array_column($state['scores'], 'points'))->toBe([1, 1])->and(CompletedRound::count())->toBe(1);
});

it('lets a timeout win over a departure at the exact deadline', function () {
    playPresenceRound($this);
    $this->travelTo(Room::first()->ends_at);
    $this->game->leave($this->evaluator);
    expect(Room::first()->status)->toBe('closed')->and(Room::first()->result)->toBe('timeout')
        ->and(CompletedRound::count())->toBe(1)->and(CompletedRound::first()->result)->toBe('timeout');
});

it('allows the remaining participant to leave a closed room without duplicating the archive', function () {
    playPresenceRound($this);
    $this->game->leave($this->evaluator);
    $this->game->leave($this->drawer);
    expect(Participant::whereNull('left_at')->count())->toBe(0)->and(CompletedRound::count())->toBe(1);
    $this->getJson('/api/room', presenceHeaders($this->a))->assertUnauthorized();
});

it('requires authentication to leave and never lets callers choose another participant', function () {
    $this->postJson('/api/room/leave', [])->assertUnauthorized();
    $this->postJson('/api/room/leave', ['participant_id' => $this->evaluator->id], presenceHeaders($this->a))->assertOk();
    expect($this->drawer->fresh()->left_at)->not->toBeNull()->and($this->evaluator->fresh()->left_at)->toBeNull();
});

it('rejects a stale authenticated model after departure even if it was loaded earlier', function () {
    $this->game->leave($this->drawer);
    expect(fn () => $this->game->state($this->drawer))->toThrow(HttpException::class);
});
