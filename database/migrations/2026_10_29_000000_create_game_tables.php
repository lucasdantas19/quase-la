<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->string('code', 6)->unique();
            $t->string('status')->default('waiting');
            $t->string('secret')->nullable();
            $t->json('aliases')->nullable();
            $t->unsignedTinyInteger('proximity')->default(0);
            $t->json('drawing');
            $t->unsignedInteger('revision')->default(0);
            $t->unsignedInteger('version')->default(0);
            $t->unsignedInteger('point_count')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ends_at')->nullable()->index();
            $t->timestamp('finished_at')->nullable();
            $t->string('result')->nullable();
            $t->timestamps();
        });
        Schema::create('participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->string('name', 30);
            $t->string('role');
            $t->string('token_hash', 64)->unique();
            $t->unique(['room_id', 'role']);
            $t->timestamps();
        });
        Schema::create('drawing_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->uuid('operation_id');
            $t->unique(['room_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawing_operations');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('rooms');
    }
};
