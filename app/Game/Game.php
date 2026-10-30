<?php

namespace App\Game;

use App\Events\GameUpdated;
use App\Models\Competition;
use App\Models\CompletedRound;
use App\Models\Guess;
use App\Models\Participant;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Game
{
    public function __construct(private RoomState $states) {}

    public function create(string $name, string $role, bool $competitive = false, ?string $competitionCode = null): array
    {
        return DB::transaction(function () use ($name, $role, $competitive, $competitionCode) {
            $competition = null;
            if ($competitionCode) {
                $competition = Competition::where('code', strtoupper($competitionCode))->lockForUpdate()->firstOrFail();
                $this->check($competition->ended_at === null, 'Esta partida foi encerrada.');
                $this->check(! $competition->started, 'Esta partida já começou.');
                $this->check($competition->rooms()->whereNull('closed_at')->count() < 8, 'Limite de oito duplas por partida.');
            } elseif ($competitive) {
                do {
                    $matchCode = strtoupper(Str::random(6));
                } while (Competition::where('code', $matchCode)->exists());
                $competition = Competition::create(['code' => $matchCode]);
            }
            do {
                $code = strtoupper(Str::random(6));
            } while (Room::where('code', $code)->exists());
            $room = Room::create(['code' => $code, 'drawing' => [], 'competition_id' => $competition?->id]);

            return $this->admit($room, $name, $role);
        });
    }

    public function join(string $code, string $name): array
    {
        return $this->roomTransaction(Room::where('code', strtoupper($code))->firstOrFail()->id, function () use ($code, $name) {
            $room = $this->lockRoom(Room::where('code', strtoupper($code))->firstOrFail()->id);
            if ($room->competition_id) {
                $competition = Competition::findOrFail($room->competition_id);
                $this->check($competition->ended_at === null, 'Esta partida foi encerrada.');
                $this->check(! $competition->started, 'Esta partida já começou.');
            }
            $this->check($room->closed_at === null && $room->status === 'waiting' && $room->participants()->count() === 1, 'Esta sala já está completa ou encerrada.');
            $role = $room->participants()->first()->role === 'drawer' ? 'evaluator' : 'drawer';
            $admission = $this->admit($room, $name, $role);
            $room->increment('version');
            $this->notify($room);

            return $admission;
        });
    }

    private function admit(Room $room, string $name, string $role): array
    {
        $token = Str::random(64);
        $participant = $room->participants()->create(['name' => $name, 'role' => $role, 'token_hash' => hash('sha256', $token), 'last_seen_at' => now(), 'is_online' => true]);

        if ($room->competition_id) {
            Competition::whereKey($room->competition_id)->whereNull('organizer_id')->update(['organizer_id' => $participant->id]);
        }

        return ['token' => $token, 'state' => $this->states->for($room->fresh(), $participant)];
    }

    public function state(Participant $actor): array
    {
        return $this->roomTransaction($actor->room_id, function () use ($actor) {
            $room = $this->lockRoom($actor->room_id);
            $connected = $this->touch($room, $actor);
            $disconnected = $this->disconnectStale($room);
            $expired = $this->expire($room);
            if ($connected || $disconnected || $expired) {
                $this->notify($room);
            }

            return $this->states->for($room, $actor);
        });
    }

    public function act(Participant $actor, string $action, array $data): array
    {
        // Expiration commits even when the following action is rejected.
        $this->roomTransaction($actor->room_id, function () use ($actor) {
            $room = $this->lockRoom($actor->room_id);
            $connected = $this->touch($room, $actor);
            $disconnected = $this->disconnectStale($room);
            $expired = $this->expire($room);
            if ($connected || $disconnected || $expired) {
                $this->notify($room);
            }
        });

        return $this->roomTransaction($actor->room_id, function () use ($actor, $action, $data) {
            $room = $this->lockRoom($actor->room_id);
            $this->check($room->closed_at === null, 'Esta sala foi encerrada. Crie uma nova sala para jogar.');
            abort_unless(isset($data['round_number']) && (int) $data['round_number'] === $room->round_number, 409, 'A rodada mudou. Sincronize a sala.');
            $actor = $room->participants->firstWhere('id', $actor->id);
            abort_unless($actor && $actor->left_at === null, 401);
            // A request can have waited for the lock across the deadline.
            if ($this->expire($room)) {
                $this->notify($room);

                return ['state' => $this->states->for($room, $actor), 'expired' => true];
            }
            $delta = null;
            $guessUpdate = null;
            $correct = null;
            if ($action === 'next') {
                $this->readyForNext($room, $actor);
            } elseif ($action === 'start') {
                if ($room->competition_id) {
                    abort_unless(Competition::findOrFail($room->competition_id)->organizer_id === $actor->id, 403);
                } else {
                    abort_unless($actor->role === 'evaluator', 403);
                }
                $this->check($room->status === 'waiting' && $room->participants()->count() === 2, 'Aguarde os dois participantes para começar.');
                $this->check($room->participants->every(fn ($p) => $p->is_online && $p->left_at === null), 'Aguarde seu parceiro reconectar para começar.');
                if ($room->competition_id) {
                    $this->startCompetition($room);
                } else {
                    $this->startRound($room);
                }
            } else {
                $this->check($room->status === 'playing', 'A rodada não está em andamento.');
                if ($action === 'feedback') {
                    abort_unless($actor->role === 'evaluator', 403);
                    $room->proximity = $data['score'];
                } elseif ($action === 'judge') {
                    abort_unless($actor->role === 'evaluator', 403);
                    // Scope the lookup to this room, under the same lock as the deadline.
                    $guess = $room->guesses()->findOrFail($data['guess_id']);
                    $this->check($guess->status === 'pending', 'Este palpite já foi avaliado.');
                    $guess->fill(['status' => $data['decision'], 'judged_by' => 'evaluator', 'judged_at' => now()])->save();
                    $guessUpdate = $guess->toHistory();
                    if ($data['decision'] === 'accepted') {
                        $room->fill(['status' => 'finished', 'result' => 'guessed', 'finished_at' => now()]);
                    }
                } else {
                    abort_unless($actor->role === 'drawer', 403);
                    if ($action === 'guess') {
                        $guess = $this->normalize($data['guess']);
                        $correct = in_array($guess, array_map($this->normalize(...), [$room->secret, ...$room->aliases]), true);
                        $guessUpdate = $room->guesses()->create([
                            'round_number' => $room->round_number,
                            'text' => trim($data['guess']),
                            'status' => $correct ? 'accepted' : 'pending',
                            'judged_by' => $correct ? 'system' : null,
                            'judged_at' => $correct ? now() : null,
                        ])->toHistory();
                        if ($correct) {
                            $room->fill(['status' => 'finished', 'result' => 'guessed', 'finished_at' => now()]);
                        }
                    } elseif ($action === 'draw') {
                        $operation = DB::table('drawing_operations')->where('room_id', $room->id)->where('operation_id', $data['id'])->first();
                        if ($operation) {
                            abort_unless((int) $operation->round_number === $room->round_number, 409, 'Operação de outra rodada.');

                            return ['state' => $this->states->for($room, $actor, false)];
                        }
                        $this->check($data['base_revision'] === $room->revision, 'O desenho mudou. Sincronize e tente novamente.');
                        if ($data['kind'] === 'clear') {
                            $room->drawing = [];
                            $room->point_count = 0;
                        } else {
                            $this->check($room->point_count + count($data['points']) <= 12000 && count($room->drawing) < 2000, 'Limite de traços atingido. Limpe o desenho para continuar.');
                            $chunk = ['id' => $data['id'], 'points' => $data['points'], 'color' => $data['color'], 'width' => $data['width']];
                            $room->drawing = [...$room->drawing, $chunk];
                            $room->point_count += count($data['points']);
                        }
                        DB::table('drawing_operations')->insert(['room_id' => $room->id, 'round_number' => $room->round_number, 'operation_id' => $data['id']]);
                        $room->revision++;
                        $delta = ['kind' => $data['kind'], 'chunk' => $chunk ?? null, 'revision' => $room->revision];
                    }
                }
            }
            $room->version++;
            $room->save();
            if ($room->status === 'finished') {
                if ($room->result === 'guessed') {
                    $this->beginFinalWindow($room);
                }
                $this->archive($room);
            }
            $this->notify($room, $delta, $guessUpdate);

            return ['state' => $this->states->for($room, $actor, false), 'correct' => $correct, 'delta' => $delta, 'guess_update' => $guessUpdate];
        });
    }

    public function expire(Room $room): bool
    {
        if ($room->status !== 'playing' || now()->lt($room->ends_at)) {
            return false;
        }
        $room->fill(['status' => 'finished', 'result' => 'timeout', 'finished_at' => $room->ends_at, 'version' => $room->version + 1])->save();

        $this->archive($room);

        return true;
    }

    public function expireDue(): void
    {
        $this->markDisconnected();
        Room::where('status', 'playing')->where('ends_at', '<=', now())->pluck('id')->each(function ($id) {
            $this->roomTransaction($id, function () use ($id) {
                $room = $this->lockRoom($id);
                if ($room && $this->expire($room)) {
                    $this->notify($room);
                }
            });
        });
    }

    // A heartbeat is a successful authenticated state read or game action.
    private function touch(Room $room, Participant $actor): bool
    {
        $participant = $room->participants->firstWhere('id', $actor->id);
        abort_unless($participant && $participant->left_at === null, 401);
        if ($room->closed_at !== null) {
            return false;
        }
        $changed = ! $participant->is_online;
        $participant->fill(['last_seen_at' => now(), 'is_online' => true])->save();
        if ($changed) {
            $room->version++;
            $room->save();
        }

        return $changed;
    }

    public function markDisconnected(): void
    {
        $cutoff = now()->subSeconds(config('game.presence_timeout_seconds'));
        $roomIds = Participant::where('is_online', true)->whereNull('left_at')->whereHas('room', fn ($query) => $query->whereNull('closed_at'))->where('last_seen_at', '<=', $cutoff)->distinct()->pluck('room_id');
        foreach ($roomIds as $id) {
            $this->roomTransaction($id, function () use ($id) {
                $room = $this->lockRoom($id);
                if (! $room || $room->closed_at !== null) {
                    return;
                }
                if (! $this->disconnectStale($room)) {
                    return;
                }
                $this->notify($room);
            });
        }
    }

    private function disconnectStale(Room $room): bool
    {
        if ($room->closed_at !== null) {
            return false;
        }
        $cutoff = now()->subSeconds(config('game.presence_timeout_seconds'));
        $offline = $room->participants->filter(fn ($p) => $p->is_online && $p->left_at === null && (! $p->last_seen_at || $p->last_seen_at->lte($cutoff)));
        if ($offline->isEmpty()) {
            return false;
        }
        foreach ($offline as $participant) {
            $participant->update(['is_online' => false]);
        }
        $room->next_ready = array_values(array_diff($room->next_ready ?? [], $offline->pluck('id')->all()));
        $room->version++;
        $room->save();

        return true;
    }

    public function leave(Participant $actor): array
    {
        return $this->roomTransaction($actor->room_id, function () use ($actor) {
            $room = $this->lockRoom($actor->room_id);
            $participant = $room->participants->firstWhere('id', $actor->id);
            abort_unless($participant && $participant->left_at === null, 401);
            // If the deadline won the race, keep the timeout result instead of abandonment.
            $this->expire($room);
            if ($room->closed_at === null) {
                if ($room->status === 'playing') {
                    $room->fill(['result' => 'abandoned', 'finished_at' => now()]);
                    $this->archive($room);
                }
                $room->fill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id, 'next_ready' => []]);
            }
            $participant->fill(['left_at' => now(), 'is_online' => false])->save();
            $room->version++;
            $room->save();
            $this->notify($room);
            if ($room->competition_id) {
                $competition = Competition::findOrFail($room->competition_id);
                $organizer = Participant::find($competition->organizer_id);
                if ($competition->ended_at === null && (! $organizer || $organizer->left_at !== null || $organizer->room->closed_at !== null)) {
                    $successor = Participant::whereNull('left_at')->whereHas('room', fn ($query) => $query->where('competition_id', $competition->id)->whereNull('closed_at'))->orderBy('id')->first();
                    $competition->update(['organizer_id' => $successor?->id]);
                }
            }
            if ($room->competition_id && Competition::findOrFail($room->competition_id)->started) {
                $remaining = Room::where('competition_id', $room->competition_id)->whereNull('closed_at')->orderBy('id')->first();
                if ($remaining) {
                    $this->advanceCompetition($remaining);
                }
            }

            return $this->states->for($room, $participant);
        });
    }

    private function startRound(Room $room): void
    {
        $objects = config('game.objects');
        $recent = $room->completedRounds()->reorder('number', 'desc')->limit(3)->pluck('secret')->all();
        // Relax the oldest exclusion first when the catalog is small.
        do {
            $available = array_values(array_filter($objects, fn ($object) => ! in_array($object['name'], $recent, true)));
            array_pop($recent);
        } while (! $available && $recent);
        $available = $available ?: $objects;
        $object = $available[random_int(0, count($available) - 1)];
        $room->fill([
            'status' => 'playing', 'secret' => $object['name'], 'aliases' => $object['aliases'],
            'started_at' => now(), 'ends_at' => now()->addSeconds(config('game.duration_seconds')),
            'finished_at' => null, 'result' => null, 'drawing' => [], 'revision' => 0,
            'point_count' => 0, 'proximity' => 0, 'next_ready' => [], 'final_window_started_at' => null,
        ]);
    }

    private function readyForNext(Room $room, Participant $actor, bool $coordinated = false): void
    {
        $this->check($room->status === 'finished', 'Aguarde o encerramento da rodada.');
        $ready = array_values(array_unique([...($room->next_ready ?? []), $actor->id]));
        $room->next_ready = $ready;
        if ($room->competition_id && ! $coordinated) {
            $room->save();
            $this->advanceCompetition($room);

            return;
        }
        if (count($ready) !== 2) {
            return;
        }

        $players = $room->participants;
        $this->check($players->count() === 2, 'São necessários dois participantes.');
        $this->check($players->every(fn ($p) => $p->is_online && $p->left_at === null), 'Aguarde seu parceiro reconectar.');
        $this->archive($room);
        $first = $players[0];
        $second = $players[1];
        $firstRole = $first->role;
        // The temporary role avoids violating UNIQUE(room_id, role) during the swap.
        $first->update(['role' => 'swapping']);
        $second->update(['role' => $firstRole]);
        $first->update(['role' => $firstRole === 'drawer' ? 'evaluator' : 'drawer']);
        $room->unsetRelation('participants');
        $room->unsetRelation('guesses');
        $room->round_number++;
        $this->startRound($room);
    }

    private function archive(Room $room): void
    {
        $room->completedRounds()->firstOrCreate(['number' => $room->round_number], [
            'secret' => $room->secret, 'result' => $room->result, 'finished_at' => $room->finished_at,
            'data' => [
                'participants' => $room->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'role' => $p->role])->all(),
                'drawing' => $room->drawing, 'proximity' => $room->proximity,
                'started_at' => $room->started_at?->toISOString(), 'ends_at' => $room->ends_at?->toISOString(),
            ],
        ]);
    }

    public function history(Participant $actor, int $number): array
    {
        $round = $actor->room->completedRounds()->where('number', $number)->firstOrFail();

        return [
            ...$round->summary(), ...$round->data,
            'guesses' => Guess::where('room_id', $actor->room_id)->where('round_number', $number)->orderBy('id')->get()->map(fn ($guess) => $guess->toHistory())->all(),
        ];
    }

    private function notify(Room $room, ?array $delta = null, ?array $guessUpdate = null, bool $snapshot = false): void
    {
        foreach ($room->participants->whereNull('left_at') as $p) {
            $payload = ['state' => $this->states->for($room, $p, $snapshot), 'delta' => $delta, 'guess_update' => $guessUpdate];
            DB::afterCommit(function () use ($p, $payload) {
                try {
                    event(new GameUpdated($p->id, $payload));
                } catch (\Throwable $e) {
                    Log::warning('Falha no broadcast; clientes recuperam estado via HTTP.', ['error' => $e->getMessage()]);
                }
            });
        }
    }

    // Resolve the immutable parent outside the transaction. Under MySQL REPEATABLE READ,
    // an earlier plain SELECT would pin a snapshot from before waiting for the parent lock.
    private function roomTransaction(int $id, callable $callback): mixed
    {
        $competitionId = Room::findOrFail($id)->competition_id;

        return DB::transaction(function () use ($competitionId, $callback) {
            if ($competitionId) {
                Competition::whereKey($competitionId)->lockForUpdate()->firstOrFail();
            }

            return $callback();
        }, 3);
    }

    private function lockRoom(int $id): Room
    {
        return Room::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function startCompetition(Room $current): void
    {
        $competition = Competition::findOrFail($current->competition_id);
        $this->check($competition->ended_at === null, 'Esta partida foi encerrada.');
        $this->check(! $competition->started, 'Esta partida já começou.');
        $teams = $competition->rooms()->whereNull('closed_at')->orderBy('id')->lockForUpdate()->get();
        $this->check($teams->count() >= 2, 'São necessárias pelo menos duas duplas.');
        foreach ($teams as $team) {
            $this->disconnectStale($team);
            $this->check($team->participants->count() === 2 && $team->participants->every(fn ($p) => $p->is_online && $p->left_at === null), 'Todas as duplas precisam estar completas e conectadas.');
        }
        $competition->update(['started' => true]);
        $template = null;
        foreach ($teams as $team) {
            $this->startRound($team);
            if ($template) {
                $team->fill($template);
            } else {
                $template = $team->only(['secret', 'aliases', 'started_at', 'ends_at']);
            }
            $team->version++;
            $team->save();
            $this->notify($team);
        }
        $current->refresh();
    }

    private function advanceCompetition(Room $current): void
    {
        if (Competition::findOrFail($current->competition_id)->ended_at !== null) {
            return;
        }
        $teams = Room::where('competition_id', $current->competition_id)->whereNull('closed_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($teams as $team) {
            $this->disconnectStale($team);
            $this->expire($team);
            if ($team->status !== 'finished' || count($team->next_ready ?? []) !== 2 || ! $team->participants->every(fn ($p) => $p->is_online && $p->left_at === null)) {
                return;
            }
        }
        $template = null;
        foreach ($teams as $team) {
            $this->readyForNext($team, $team->participants->first(), true);
            if ($template) {
                $team->fill($template);
            } else {
                $template = $team->only(['secret', 'aliases', 'started_at', 'ends_at']);
            }
            $team->version++;
            $team->save();
            $this->notify($team);
        }
        $current->refresh();
    }

    public function manageCompetition(Participant $actor, string $action, array $data): array
    {
        return $this->roomTransaction($actor->room_id, function () use ($actor, $action, $data) {
            $room = $this->lockRoom($actor->room_id);
            abort_unless($room->competition_id, 404);
            $participant = $room->participants->firstWhere('id', $actor->id);
            abort_unless($participant && $participant->left_at === null, 401);
            $competition = Competition::findOrFail($room->competition_id);
            abort_unless($competition->organizer_id === $actor->id, 403);
            abort_unless((int) $data['round_number'] === $room->round_number, 409, 'A rodada mudou. Sincronize a sala.');
            if ($competition->ended_at !== null) {
                $this->check($action === 'end', 'Esta partida foi encerrada.');

                return $this->states->for($room, $participant);
            }
            $this->check($room->closed_at === null, 'Esta sala foi encerrada.');
            if ($action === 'remove') {
                $this->check(! $competition->started, 'Duplas só podem ser removidas no lobby.');
                $target = $competition->rooms()->whereKey($data['room_id'])->lockForUpdate()->firstOrFail();
                $this->check($target->id !== $room->id && $target->closed_at === null && $target->participants()->count() < 2, 'Escolha outra dupla incompleta do lobby.');
                $target->fill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id, 'close_reason' => 'removed', 'next_ready' => [], 'version' => $target->version + 1])->save();
                $this->notify($target);
                $room->increment('version');
                $this->notify($room);
            } else {
                abort_unless($action === 'end', 404);
                $teams = $competition->rooms()->orderBy('id')->lockForUpdate()->get();
                foreach ($teams as $team) {
                    if ($team->closed_at !== null) {
                        $team->increment('version');

                        continue;
                    }
                    $this->expire($team);
                    if ($team->status === 'playing') {
                        $team->fill(['result' => 'abandoned', 'finished_at' => now()]);
                        $this->archive($team);
                    }
                    $team->fill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id, 'close_reason' => 'competition_ended', 'next_ready' => [], 'version' => $team->version + 1])->save();
                }
                $competition->update(['ended_at' => now(), 'ended_by' => $actor->id]);
                $room->refresh();
                $final = $this->states->for($room, $participant)['competition']['teams'];
                $competition->update(['final_standings' => $final]);
                foreach ($teams as $team) {
                    // Include the final snapshot so every participant sees the result immediately.
                    $this->notify($team, snapshot: true);
                }
            }

            return $this->states->for($room->fresh(), $participant);
        });
    }

    private function beginFinalWindow(Room $winner): void
    {
        if (! $winner->competition_id || $winner->final_window_started_at !== null) {
            return;
        }
        $teams = Room::where('competition_id', $winner->competition_id)->where('round_number', $winner->round_number)
            ->whereNull('closed_at')->orderBy('id')->lockForUpdate()->get();
        if ($teams->contains(fn ($team) => $team->final_window_started_at !== null)
            || ! $teams->contains(fn ($team) => $team->status === 'playing')
            || CompletedRound::whereIn('room_id', $teams->pluck('id'))->where('number', $winner->round_number)->where('result', 'guessed')->exists()) {
            return;
        }
        // The competition lock serializes automatic wins and human judgments. Later
        // winners cannot renew this deadline; the original round limit always wins.
        $startedAt = $winner->finished_at;
        $deadline = $winner->ends_at->min($startedAt->addSeconds(30));
        foreach ($teams as $team) {
            $team->fill([
                'final_window_started_at' => $startedAt,
                'ends_at' => $team->ends_at->min($deadline),
                'version' => $team->version + 1,
            ])->save();
            if ($team->id !== $winner->id) {
                $this->notify($team);
            }
        }
        $winner->refresh();
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['game' => $message]);
        }
    }
}
