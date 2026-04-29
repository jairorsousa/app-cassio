<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('average_price', 15, 4)->default(0);
            $table->decimal('total_invested', 15, 2)->default(0);
            $table->decimal('realized_pnl_total', 15, 2)->default(0);
            $table->decimal('current_price', 15, 4)->nullable();
            $table->timestamp('recalculated_at')->nullable();
            $table->timestamps();

            $table->unique('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_positions');
    }
};
