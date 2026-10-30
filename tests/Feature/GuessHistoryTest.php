<?php

use App\Game\Game;
use App\Models\Guess;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['game.objects' => [['name' => 'violão', 'aliases' => ['guitarra acústica']]]]);
    $this->game = app(Game::class);
    $drawer = $this->game->create('Ana', 'drawer');
    $evaluator = $this->game->join($drawer['state']['code'], 'Bia');
    $this->drawer = Participant::find($drawer['state']['you']['id']);
    $this->evaluator = Participant::find($evaluator['state']['you']['id']);
    $this->drawerHeaders = ['Authorization' => 'Bearer '.$drawer['token']];
    $this->evaluatorHeaders = ['Authorization' => 'Bearer '.$evaluator['token']];
    $this->game->act($this->evaluator, 'start', ['round_number' => 1, ...[]]);
    $this->pending = $this->game->act($this->drawer, 'guess', ['round_number' => 1, ...['guess' => '  guitarra  ']])['guess_update'];
});

it('persists every guess in order and restores history for both roles', function () {
    $this->game->act($this->drawer, 'guess', ['round_number' => 1, ...['guess' => 'guitarra']]);
    $correct = $this->game->act($this->drawer, 'guess', ['round_number' => 1, ...['guess' => 'VIOLAO!']]);
    foreach ([$this->drawer, $this->evaluator] as $participant) {
        $history = $this->game->state($participant)['guesses'];
        expect(array_column($history, 'text'))->toBe(['guitarra', 'guitarra', 'VIOLAO!'])
            ->and(array_column($history, 'status'))->toBe(['pending', 'pending', 'accepted'])
            ->and($history[0]['judged_by'])->toBeNull()
            ->and($history[0]['judged_at'])->toBeNull()
            ->and($history[2]['judged_by'])->toBe('system')
            ->and($history[2]['judged_at'])->not->toBeNull();
    }
    expect($correct['correct'])->toBeTrue()->and(Guess::count())->toBe(3);
});

it('allows only the evaluator to accept a pending guess and finishes the round', function () {
    $response = $this->postJson('/api/room/judge', ['round_number' => 1, ...[
        'guess_id' => $this->pending['id'], 'decision' => 'accepted', 'message' => 'dica proibida',
    ]], $this->evaluatorHeaders)->assertOk()
        ->assertJsonPath('state.status', 'finished')
        ->assertJsonPath('state.result', 'guessed')
        ->assertJsonPath('guess_update.status', 'accepted')
        ->assertJsonPath('guess_update.judged_by', 'evaluator');
    $state = $this->game->state($this->drawer);
    expect($state['secret'])->toBe('violão')
        ->and($state['guesses'][0]['status'])->toBe('accepted')
        ->and($state['guesses'][0]['judged_at'])->not->toBeNull()
        ->and($response->getContent())->not->toContain('dica proibida');
    $this->postJson('/api/room/judge', ['round_number' => 1, ...['guess_id' => $this->pending['id'], 'decision' => 'rejected']], $this->evaluatorHeaders)->assertUnprocessable();
    expect($this->game->state($this->drawer)['guesses'][0]['status'])->toBe('accepted');
});

it('rejects a pending guess without penalty or secret disclosure and allows further guesses', function () {
    $deadline = $this->drawer->room->fresh()->ends_at;
    $this->postJson('/api/room/judge', ['round_number' => 1, ...['guess_id' => $this->pending['id'], 'decision' => 'rejected']], $this->evaluatorHeaders)
        ->assertOk()->assertJsonPath('state.status', 'playing')->assertJsonPath('guess_update.status', 'rejected');
    Auth::forgetGuards();
    $this->getJson('/api/room', $this->drawerHeaders)->assertOk()
        ->assertJsonPath('guesses.0.status', 'rejected')->assertJsonPath('guesses.0.judged_by', 'evaluator')
        ->assertJsonMissingPath('secret')->assertJsonMissingPath('proximity')->assertJsonMissingPath('aliases');
    expect($this->drawer->room->fresh()->ends_at->equalTo($deadline))->toBeTrue();
    expect($this->game->act($this->drawer, 'guess', ['round_number' => 1, ...['guess' => 'outro palpite']])['guess_update']['status'])->toBe('pending');
});

it('prevents the drawer from judging their own guess', function () {
    $this->postJson('/api/room/judge', ['round_number' => 1, ...['guess_id' => $this->pending['id'], 'decision' => 'accepted']], $this->drawerHeaders)->assertForbidden();
    expect(Guess::find($this->pending['id'])->status)->toBe('pending');
});

it('prevents judgments of guesses from another room or nonexistent guesses', function () {
    $other = $this->game->create('Outra pessoa', 'drawer');
    $partner = $this->game->join($other['state']['code'], 'Outro avaliador');
    $d = Participant::find($other['state']['you']['id']);
    $e = Participant::find($partner['state']['you']['id']);
    $this->game->act($e, 'start', ['round_number' => 1, ...[]]);
    $guess = $this->game->act($d, 'guess', ['round_number' => 1, ...['guess' => 'outro']])['guess_update'];
    foreach ([$guess['id'], 999999] as $id) {
        Auth::forgetGuards();
        $this->postJson('/api/room/judge', ['round_number' => 1, ...['guess_id' => $id, 'decision' => 'accepted']], $this->evaluatorHeaders)->assertNotFound();
    }
    expect(Guess::find($guess['id'])->status)->toBe('pending')
        ->and($this->game->state($this->evaluator)['guesses'])->toHaveCount(1);
});

it('rejects decisions with invalid input', function (array $data) {
    $this->postJson('/api/room/judge', ['round_number' => 1, ...$data], $this->evaluatorHeaders)->assertUnprocessable();
    expect(Guess::find($this->pending['id'])->status)->toBe('pending');
})->with([[
    ['decision' => 'accepted'],
], [
    ['guess_id' => -1, 'decision' => 'accepted'],
], [
    ['guess_id' => 1, 'decision' => 'quase acertou'],
], [
    ['guess_id' => 1],
]]);

it('does not allow changing a completed judgment', function () {
    $this->game->act($this->evaluator, 'judge', ['round_number' => 1, ...['guess_id' => $this->pending['id'], 'decision' => 'rejected']]);
    $this->postJson('/api/room/judge', ['round_number' => 1, ...['guess_id' => $this->pending['id'], 'decision' => 'accepted']], $this->evaluatorHeaders)->assertUnprocessable();
    expect(Guess::find($this->pending['id'])->status)->toBe('rejected')
        ->and($this->game->state($this->drawer)['status'])->toBe('playing');
});

it('refuses judgments at the deadline even for guesses sent earlier', function () {
    $this->travelTo($this->drawer->room->fresh()->ends_at);
    $this->postJson('/api/room/judge', ['round_number' => 1, ...['guess_id' => $this->pending['id'], 'decision' => 'accepted']], $this->evaluatorHeaders)->assertUnprocessable();
    expect($this->game->state($this->drawer)['result'])->toBe('timeout')
        ->and(Guess::find($this->pending['id'])->status)->toBe('pending');
});

it('allows an evaluator judgment immediately before the deadline', function () {
    $this->travelTo($this->drawer->room->fresh()->ends_at->subSecond());
    $this->postJson('/api/room/judge', ['round_number' => 1, ...['guess_id' => $this->pending['id'], 'decision' => 'accepted']], $this->evaluatorHeaders)
        ->assertOk()->assertJsonPath('state.result', 'guessed');
});

it('keeps the full history out of drawing and feedback updates', function () {
    $result = $this->game->act($this->evaluator, 'feedback', ['round_number' => 1, ...['score' => 70]]);
    expect($result['state'])->not->toHaveKey('guesses')->and($result['guess_update'])->toBeNull();
});
