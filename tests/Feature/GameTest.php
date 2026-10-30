<?php

use App\Game\Game;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['game.objects' => [['name' => 'violão', 'aliases' => ['guitarra acústica']]]]);
    $this->game = app(Game::class);
    $this->drawer = $this->game->create('Ana', 'drawer');
    $this->evaluator = $this->game->join($this->drawer['state']['code'], 'Bia');
    $this->d = Participant::find($this->drawer['state']['you']['id']);
    $this->e = Participant::find($this->evaluator['state']['you']['id']);
});
function chunk(array $overrides = []): array
{
    return array_replace(['id' => (string) Str::uuid(), 'kind' => 'stroke', 'base_revision' => 0, 'points' => [[10, 20], [30, 40]], 'color' => '#18252b', 'width' => 5], $overrides);
}
function headersFor(array $admission): array
{
    Auth::forgetGuards();

    return ['Authorization' => 'Bearer '.$admission['token']];
}

it('assigns complementary roles and hashes independent credentials', function () {
    expect($this->drawer['state']['you']['role'])->toBe('drawer')
        ->and($this->evaluator['state']['you']['role'])->toBe('evaluator')
        ->and($this->d->token_hash)->toBe(hash('sha256', $this->drawer['token']))
        ->and($this->drawer['token'])->not->toBe($this->evaluator['token']);
    $other = $this->game->create('Caio', 'evaluator');
    expect($this->game->join($other['state']['code'], 'Dani')['state']['you']['role'])->toBe('drawer');
});
it('rejects a third participant and unknown room codes', function () {
    $this->postJson('/api/rooms/join', ['name' => 'Intruso', 'code' => $this->drawer['state']['code']])->assertUnprocessable();
    $this->postJson('/api/rooms/join', ['name' => 'Intruso', 'code' => 'ZZZZZZ'])->assertNotFound();
    expect($this->d->room->participants()->count())->toBe(2);
});
it('requires two players and evaluator authorization to start', function () {
    $this->postJson('/api/room/start', ['round_number' => 1, ...[]], headersFor($this->drawer))->assertForbidden();
    $solo = $this->game->create('Solo', 'evaluator');
    $this->postJson('/api/room/start', ['round_number' => 1, ...[]], headersFor($solo))->assertUnprocessable();
    $this->postJson('/api/room/start', ['round_number' => 1, ...[]], headersFor($this->evaluator))->assertOk()->assertJsonPath('state.status', 'playing');
    $room = $this->d->room->fresh();
    expect($room->started_at->diffInSeconds($room->ends_at))->toEqual(180);
    $this->postJson('/api/room/start', ['round_number' => 1, ...[]], headersFor($this->evaluator))->assertUnprocessable();
});
it('never exposes the secret aliases or numeric score to the drawer during play', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $this->postJson('/api/room/feedback', ['round_number' => 1, ...['score' => 95, 'secret' => 'injetado', 'message' => 'dica textual']], headersFor($this->evaluator))->assertOk();
    $this->getJson('/api/room', headersFor($this->drawer))->assertOk()->assertJsonPath('temperature', 'muito quente')->assertJsonMissingPath('secret')->assertJsonMissingPath('proximity')->assertJsonMissingPath('aliases');
    $this->getJson('/api/room', headersFor($this->evaluator))->assertOk()->assertJsonPath('secret', 'violão')->assertJsonPath('proximity', 95)->assertJsonMissingPath('aliases');
    $state = $this->game->state($this->d);
    expect(json_encode($state))->not->toContain('violão', 'acústica', 'dica textual');
});
it('rejects actions assigned to the other role', function (string $role, string $action, array $data) {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $this->postJson('/api/room/'.$action, ['round_number' => 1, ...$data], headersFor($this->{$role}))->assertForbidden();
})->with([['drawer', 'feedback', ['score' => 50]], ['evaluator', 'guess', ['guess' => 'violão']], ['evaluator', 'draw', ['id' => '01537e8b-b705-47ca-b81d-bbfbc08c7cda', 'kind' => 'clear', 'base_revision' => 0]]]);
it('validates slider and stroke payloads', function (string $action, array $data) {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $this->postJson('/api/room/'.$action, ['round_number' => 1, ...$data], headersFor($action === 'feedback' ? $this->evaluator : $this->drawer))->assertUnprocessable();
})->with([['feedback', ['score' => 101]], ['feedback', ['score' => -1]], ['feedback', ['score' => 'quente']], ['draw', chunk(['points' => [[1001, 3]]])], ['draw', chunk(['points' => [[1, 2, 3]]])], ['draw', chunk(['points' => [['x' => 1, 'y' => 2]]])], ['draw', chunk(['color' => 'secret text'])], ['draw', chunk(['width' => 100])], ['draw', chunk(['points' => array_fill(0, 41, [1, 2])])]]);
it('accepts repeated wrong guesses without penalties and ends immediately on a normalized correct guess', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $deadline = $this->d->room->fresh()->ends_at;
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/room/guess', ['round_number' => 1, ...['guess' => 'errado']], headersFor($this->drawer))->assertOk()->assertJsonPath('correct', false)->assertJsonMissingPath('state.secret');
    }
    expect($this->d->room->fresh()->ends_at->equalTo($deadline))->toBeTrue();
    $this->postJson('/api/room/guess', ['round_number' => 1, ...['guess' => '  VIOLAO!!! ']], headersFor($this->drawer))->assertOk()->assertJsonPath('correct', true)->assertJsonPath('state.status', 'finished')->assertJsonPath('state.result', 'guessed')->assertJsonPath('state.secret', 'violão');
    $this->postJson('/api/room/feedback', ['round_number' => 1, ...['score' => 50]], headersFor($this->evaluator))->assertUnprocessable();
    $this->postJson('/api/room/guess', ['round_number' => 1, ...['guess' => 'violão']], headersFor($this->drawer))->assertUnprocessable();
});
it('accepts configured aliases but rejects partial matches', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    expect($this->game->act($this->d, 'guess', ['round_number' => 1, ...['guess' => 'viol']])['correct'])->toBeFalse()
        ->and($this->game->act($this->d, 'guess', ['round_number' => 1, ...['guess' => 'GUITARRA ACUSTICA']])['correct'])->toBeTrue();
});
it('rejects a guess exactly at the authoritative deadline and persists expiration', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $deadline = $this->d->room->fresh()->ends_at;
    $this->travelTo($deadline);
    $this->postJson('/api/room/guess', ['round_number' => 1, ...['guess' => 'violão']], headersFor($this->drawer))->assertUnprocessable();
    $this->getJson('/api/room', headersFor($this->drawer))->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('result', 'timeout')->assertJsonPath('secret', 'violão');
    expect($this->d->room->fresh()->finished_at->equalTo($deadline))->toBeTrue();
});
it('allows a correct guess just before the deadline', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $this->travelTo($this->d->room->fresh()->ends_at->subSecond());
    expect($this->game->act($this->d, 'guess', ['round_number' => 1, ...['guess' => 'violao']])['correct'])->toBeTrue();
});
it('expires rounds without an active browser and is idempotent', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $this->travel(181)->seconds();
    $this->artisan('game:expire')->assertExitCode(0);
    $version = $this->d->room->fresh()->version;
    $this->artisan('game:expire')->assertExitCode(0);
    expect($this->d->room->fresh()->status)->toBe('finished')->and($this->d->room->fresh()->version)->toBe($version);
});
it('persists vector chunks, prevents duplicate delivery and restores a snapshot', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $chunk = chunk();
    $this->postJson('/api/room/draw', ['round_number' => 1, ...$chunk], headersFor($this->drawer))->assertOk()->assertJsonPath('state.revision', 1)->assertJsonMissingPath('state.drawing');
    $this->postJson('/api/room/draw', ['round_number' => 1, ...$chunk], headersFor($this->drawer))->assertOk();
    $snapshot = $this->getJson('/api/room', headersFor($this->evaluator))->assertOk()->assertJsonPath('revision', 1)->json();
    expect($snapshot['drawing'])->toHaveCount(1)->and($snapshot['drawing'][0]['points'])->toBe([[10, 20], [30, 40]]);
    $this->postJson('/api/room/draw', ['round_number' => 1, ...chunk()], headersFor($this->drawer))->assertUnprocessable();
    $this->postJson('/api/room/draw', ['round_number' => 1, ...chunk(['kind' => 'clear', 'base_revision' => 1])], headersFor($this->drawer))->assertOk();
    $this->getJson('/api/room', headersFor($this->drawer))->assertJsonPath('drawing', [])->assertJsonPath('revision', 2);
    // A delayed duplicate from before the clear must never resurrect an old stroke.
    $this->postJson('/api/room/draw', ['round_number' => 1, ...$chunk], headersFor($this->drawer))->assertOk();
    expect($this->d->room->fresh()->drawing)->toBe([]);
});
it('isolates drawings and room state between unrelated pairs', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $this->game->act($this->d, 'draw', ['round_number' => 1, ...chunk()]);
    $other = $this->game->create('Outro', 'drawer');
    $p = Participant::find($other['state']['you']['id']);
    expect($this->game->state($p)['drawing'])->toBe([])->and($this->game->state($p)['code'])->not->toBe($this->drawer['state']['code']);
});
it('requires a valid bearer token and authorizes only the participants own private channel', function () {
    $this->getJson('/api/room')->assertUnauthorized();
    $this->getJson('/api/room', ['Authorization' => 'Bearer fake'])->assertUnauthorized();
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
    $auth = ['socket_id' => '123.456', 'channel_name' => 'private-participants.'.$this->d->id];
    $this->postJson('/api/broadcasting/auth', $auth, headersFor($this->drawer))->assertOk()->assertJsonStructure(['auth']);
    $this->postJson('/api/broadcasting/auth', $auth, headersFor($this->evaluator))->assertForbidden();
    $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-rooms.'.$this->d->room_id], headersFor($this->drawer))->assertForbidden();
});
it('enforces bounded drawing storage', function () {
    $this->game->act($this->e, 'start', ['round_number' => 1, ...[]]);
    $this->d->room->update(['point_count' => 12000]);
    $this->postJson('/api/room/draw', ['round_number' => 1, ...chunk()], headersFor($this->drawer))->assertUnprocessable();
    $this->postJson('/api/room/draw', ['round_number' => 1, ...chunk(['kind' => 'clear'])], headersFor($this->drawer))->assertOk();
    $this->postJson('/api/room/draw', ['round_number' => 1, ...chunk(['base_revision' => 1])], headersFor($this->drawer))->assertOk();
});
