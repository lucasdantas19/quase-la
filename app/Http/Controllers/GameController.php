<?php

namespace App\Http\Controllers;

use App\Game\Game;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GameController extends Controller
{
    public function create(Request $r, Game $game)
    {
        $d = $r->validate(['competitive' => 'sometimes|boolean', 'competition_code' => 'nullable|string|size:6|alpha_num:ascii', 'name' => 'required|string|max:30', 'role' => ['required', Rule::in(['drawer', 'evaluator'])]]);

        return response()->json($game->create($d['name'], $d['role'], $d['competitive'] ?? false, $d['competition_code'] ?? null), 201);
    }

    public function join(Request $r, Game $game)
    {
        $d = $r->validate(['name' => 'required|string|max:30', 'code' => 'required|string|size:6|alpha_num:ascii']);

        return response()->json($game->join($d['code'], $d['name']), 201);
    }

    public function state(Request $r, Game $game)
    {
        return $game->state($r->user());
    }

    public function leave(Request $r, Game $game)
    {
        return $game->leave($r->user());
    }

    public function history(Request $r, Game $game, int $number)
    {
        return $game->history($r->user(), $number);
    }

    public function competition(Request $r, Game $game, string $action)
    {
        abort_unless(in_array($action, ['end', 'remove'], true), 404);
        $data = $r->validate(['round_number' => 'required|integer|min:1', ...($action === 'remove' ? ['room_id' => 'required|integer|min:1'] : [])]);

        return $game->manageCompetition($r->user(), $action, $data);
    }

    public function action(Request $r, Game $game, string $action)
    {
        abort_unless(in_array($action, ['start', 'next', 'feedback', 'guess', 'judge', 'draw'], true), 404);
        $rules = match ($action) {
            'start', 'next' => [], 'feedback' => ['score' => 'required|integer|between:0,100'],
            'guess' => ['guess' => 'required|string|max:100'],
            'judge' => ['guess_id' => 'required|integer|min:1', 'decision' => ['required', Rule::in(['accepted', 'rejected'])]],
            'draw' => [
                'id' => 'required|uuid', 'base_revision' => 'required|integer|min:0', 'kind' => ['required', Rule::in(['stroke', 'clear'])],
                'points' => 'required_if:kind,stroke|array|list|min:1|max:40',
                'points.*' => 'required|array|list|size:2', 'points.*.*' => 'required|numeric|between:0,1000',
                'color' => ['required_if:kind,stroke', Rule::in(['#18252b', '#ef6351', '#3286b8', '#e7ad35', '#ffffff'])],
                'width' => 'required_if:kind,stroke|integer|between:1,40',
            ],
        };

        return $game->act($r->user(), $action, $r->validate(['round_number' => 'required|integer|min:1', ...$rules]));
    }
}
