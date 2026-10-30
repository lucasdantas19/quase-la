<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('round_number')->default(1);
            $table->json('next_ready')->nullable();
        });
        Schema::table('guesses', function (Blueprint $table) {
            $table->unsignedInteger('round_number')->default(1);
            $table->index(['room_id', 'round_number', 'id']);
        });
        Schema::table('drawing_operations', function (Blueprint $table) {
            $table->unsignedInteger('round_number')->default(1);
        });
        Schema::create('completed_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('secret');
            $table->string('result');
            $table->timestamp('finished_at');
            $table->json('data');
            $table->unique(['room_id', 'number']);
        });

        // Preserve already completed first rounds without depending on application models.
        DB::table('rooms')->where('status', 'finished')->orderBy('id')->chunkById(100, function ($rooms) {
            foreach ($rooms as $room) {
                $players = DB::table('participants')->where('room_id', $room->id)->orderBy('id')->get(['id', 'name', 'role']);
                DB::table('completed_rounds')->insert([
                    'room_id' => $room->id, 'number' => 1, 'secret' => $room->secret,
                    'result' => $room->result, 'finished_at' => $room->finished_at,
                    'data' => json_encode([
                        'participants' => $players->toArray(), 'drawing' => json_decode($room->drawing, true),
                        'proximity' => $room->proximity, 'started_at' => $room->started_at, 'ends_at' => $room->ends_at,
                    ], JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completed_rounds');
        Schema::table('drawing_operations', fn (Blueprint $table) => $table->dropColumn('round_number'));
        Schema::table('guesses', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'round_number', 'id']);
            $table->dropColumn('round_number');
        });
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn(['round_number', 'next_ready']));
    }
};
