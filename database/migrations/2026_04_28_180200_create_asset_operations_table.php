<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['buy', 'sell']);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('fees', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->decimal('realized_pnl', 15, 2)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_operations');
    }
};
