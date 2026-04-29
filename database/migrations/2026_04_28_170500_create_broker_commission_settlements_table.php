<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_commission_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained('broker_commissions')->cascadeOnDelete();
            $table->foreignId('advance_id')->constrained('broker_advances')->cascadeOnDelete();
            $table->decimal('amount_offset', 15, 2); // valor abatido
            $table->timestamp('settled_at');
            $table->timestamps();

            $table->index(['commission_id']);
            $table->index(['advance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_commission_settlements');
    }
};
