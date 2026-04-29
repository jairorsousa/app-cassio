<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writ_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('writ_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->timestamp('transitioned_at');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['writ_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writ_stage_history');
    }
};
