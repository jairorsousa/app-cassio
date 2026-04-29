<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_amount', 15, 2); // valor base para cálculo
            $table->decimal('percentage_applied', 6, 3); // % efetivamente aplicado
            $table->decimal('commission_amount', 15, 2); // valor da comissão
            $table->enum('status', ['pending', 'paid', 'partially_paid'])->default('pending');
            $table->date('reference_date');
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'status']);
            $table->index(['broker_id', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_commissions');
    }
};
