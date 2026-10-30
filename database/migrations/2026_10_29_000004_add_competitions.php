<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 6)->unique();
            $t->boolean('started')->default(false);
            $t->timestamps();
        });
        Schema::table('rooms', function (Blueprint $t) {
            $t->foreignId('competition_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->dropConstrainedForeignId('competition_id'));
        Schema::dropIfExists('competitions');
    }
};
