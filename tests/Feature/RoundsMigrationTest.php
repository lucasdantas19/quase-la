<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

it('upgrades existing rooms preserving completed and active first rounds', function () {
    $migration = require database_path('migrations/2026_10_29_000002_add_multiple_rounds.php');
    $migration->down();
    $roomData = [
        'secret' => 'violão', 'aliases' => '[]', 'drawing' => json_encode([['id' => 'old', 'points' => [[10, 20]], 'color' => '#18252b', 'width' => 5]]),
        'proximity' => 85, 'started_at' => '2026-10-29 12:00:00', 'ends_at' => '2026-10-29 12:03:00',
        'created_at' => now(), 'updated_at' => now(),
    ];
    $finished = DB::table('rooms')->insertGetId([...$roomData, 'code' => 'OLD001', 'status' => 'finished', 'result' => 'guessed', 'finished_at' => '2026-10-29 12:01:00']);
    $active = DB::table('rooms')->insertGetId([...$roomData, 'code' => 'OLD002', 'status' => 'playing']);
    $waiting = DB::table('rooms')->insertGetId(['code' => 'OLD003', 'status' => 'waiting', 'drawing' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('participants')->insert([
        ['room_id' => $finished, 'name' => 'Ana', 'role' => 'drawer', 'token_hash' => hash('sha256', 'a')],
        ['room_id' => $finished, 'name' => 'Bia', 'role' => 'evaluator', 'token_hash' => hash('sha256', 'b')],
    ]);
    DB::table('guesses')->insert(['room_id' => $finished, 'text' => 'violao', 'status' => 'accepted', 'judged_by' => 'system', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('drawing_operations')->insert(['room_id' => $finished, 'operation_id' => (string) Str::uuid()]);
    $migration->up();
    $archive = DB::table('completed_rounds')->where('room_id', $finished)->first();
    $data = json_decode($archive->data, true);
    expect(DB::table('rooms')->count())->toBe(3)->and(DB::table('completed_rounds')->count())->toBe(1)
        ->and($archive->number)->toBe(1)->and($archive->secret)->toBe('violão')
        ->and($data['drawing'][0]['points'])->toBe([[10, 20]])->and($data['participants'][0]['role'])->toBe('drawer')
        ->and(DB::table('guesses')->first()->round_number)->toBe(1)
        ->and(DB::table('drawing_operations')->first()->round_number)->toBe(1)
        ->and(DB::table('rooms')->find($active)->status)->toBe('playing')
        ->and(DB::table('rooms')->find($waiting)->round_number)->toBe(1);
});
