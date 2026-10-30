<?php

namespace App\Game;

use App\Models\Competition;
use App\Models\CompletedRound;
use App\Models\Participant;
use App\Models\Room;

class RoomState
{
    public function for(Room $room, Participant $viewer, bool $snapshot = true): array
    {
        // Never trust a previously loaded role after a round transition.
        $viewer = $room->participants->firstWhere('id', $viewer->id);
        abort_unless($viewer, 403);
        $state = [
            'close_reason' => $room->close_reason, 'closed_at' => $room->closed_at?->toISOString(), 'closed_by' => $room->closed_by,
            'round_number' => $room->round_number, 'next_ready' => $room->next_ready ?? [],
            'id' => $room->id, 'code' => $room->code, 'status' => $room->status,
            'version' => $room->version, 'revision' => $room->revision,
            'you' => ['id' => $viewer->id, 'name' => $viewer->name, 'role' => $viewer->role],
            'participants' => $room->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'role' => $p->role, 'is_online' => $p->is_online, 'left_at' => $p->left_at?->toISOString()])->values()->all(),
            'temperature' => Temperature::fromScore($room->proximity),
            'server_now' => now()->toISOString(), 'ends_at' => $room->ends_at?->toISOString(),
            'result' => $room->result, 'final_window_started_at' => $room->final_window_started_at?->toISOString(),
        ];
        if ($snapshot) {
            $history = $room->completedRounds()->get(['number', 'secret', 'result', 'finished_at'])->map(fn ($round) => $round->summary())->all();
            $state['rounds'] = $history;
            $points = array_sum(array_column($history, 'points_each'));
            $state['scores'] = $room->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'points' => $points])->all();
            if ($room->competition_id) {
                $competition = Competition::findOrFail($room->competition_id);
                $teams = $competition->rooms()->with('participants')->orderBy('id')->get()->reject(fn ($team) => $team->close_reason === 'removed')->values();
                // Archive IDs give a stable, server-serialized order even for same-second wins.
                $rounds = CompletedRound::whereIn('room_id', $teams->pluck('id'))->orderBy('id')->get();
                $teamCount = $competition->started ? $teams->whereNotNull('started_at')->count() : $teams->whereNull('closed_at')->count();
                $points = array_fill_keys($teams->pluck('id')->all(), 0);
                $positions = [];
                foreach ($rounds as $round) {
                    if ($round->result === 'guessed') {
                        $position = ($positions[$round->number] ?? 0) + 1;
                        $positions[$round->number] = $position;
                        $points[$round->room_id] += max(1, $teamCount - $position + 1);
                    }
                }
                $standings = $teams->map(fn ($team) => [
                    'id' => $team->id,
                    'names' => $team->participants->pluck('name')->all(),
                    'status' => $team->status, 'round_number' => $team->round_number,
                    'ready' => count($team->next_ready ?? []),
                    'players' => $team->participants->count(),
                    'online' => $team->participants->where('is_online', true)->whereNull('left_at')->count(),
                    'points' => $points[$team->id],
                ])->sort(fn ($a, $b) => ($b['points'] <=> $a['points']) ?: ($a['id'] <=> $b['id']))->values()->all();
                $state['competition'] = ['code' => $competition->code, 'started' => (bool) $competition->started, 'team_count' => $teamCount, 'teams' => $competition->final_standings ?? $standings, 'ended_at' => $competition->ended_at?->toISOString(), 'organizer_id' => $competition->organizer_id, 'organizer_name' => Participant::find($competition->organizer_id)?->name, 'is_organizer' => $competition->organizer_id === $viewer->id];
            }
            $state['drawing'] = $room->drawing;
            $state['guesses'] = $room->guesses->map(fn ($guess) => $guess->toHistory())->all();
        }
        if ($viewer->role === 'evaluator') {
            $state['proximity'] = $room->proximity;
        }
        if ($viewer->role === 'evaluator' || in_array($room->status, ['finished', 'closed'], true)) {
            $state['secret'] = $room->secret;
        }

        return $state;
    }
}
