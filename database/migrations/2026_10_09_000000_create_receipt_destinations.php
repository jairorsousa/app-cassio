<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('receipt_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('transactions')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('beneficiary');
            $table->decimal('amount', 15, 2);
            $table->foreignId('commission_id')->nullable()->unique()->constrained('broker_commissions')->restrictOnDelete();
            $table->boolean('commission_created')->default(false);
            $table->timestamps();
        });
        Schema::create('receipt_destination_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained('receipt_destinations')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->boolean('transaction_created')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_destination_payments');
        Schema::dropIfExists('receipt_destinations');
    }
};
