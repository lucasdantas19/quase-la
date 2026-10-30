<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_online')->default(false);
            $table->timestamp('left_at')->nullable();
            $table->index(['is_online', 'last_seen_at']);
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn(['closed_at', 'closed_by']));
        Schema::table('participants', function (Blueprint $table) {
            $table->dropIndex(['is_online', 'last_seen_at']);
            $table->dropColumn(['last_seen_at', 'is_online', 'left_at']);
        });
    }
};
