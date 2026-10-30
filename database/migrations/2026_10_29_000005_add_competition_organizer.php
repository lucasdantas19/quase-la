<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->unsignedBigInteger('organizer_id')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->json('final_standings')->nullable();
        });
        Schema::table('rooms', fn (Blueprint $table) => $table->string('close_reason')->nullable());
        DB::table('competitions')->orderBy('id')->chunkById(100, function ($competitions) {
            foreach ($competitions as $competition) {
                $organizer = DB::table('participants')->join('rooms', 'rooms.id', '=', 'participants.room_id')
                    ->where('rooms.competition_id', $competition->id)->whereNull('rooms.closed_at')->whereNull('participants.left_at')
                    ->orderBy('participants.id')->value('participants.id');
                DB::table('competitions')->where('id', $competition->id)->update(['organizer_id' => $organizer]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn('close_reason'));
        Schema::table('competitions', fn (Blueprint $table) => $table->dropColumn(['organizer_id', 'ended_at', 'ended_by', 'final_standings']));
    }
};
