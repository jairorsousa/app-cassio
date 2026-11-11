<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Financiamento de bens: entrada + parcelas (conta por parcela, pode ficar vazia). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_down_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $t->string('status', 10)->default('paid'); // paid | planned
            $t->date('date')->nullable();
            $t->decimal('amount', 15, 2);
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->boolean('linked_existing')->default(false);
            $t->unsignedBigInteger('linked_previous_category_id')->nullable();
            $t->foreign('linked_previous_category_id', 'pdp_prev_cat_fk')->references('id')->on('categories')->nullOnDelete();
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['property_id', 'status']);
        });

        Schema::create('property_financings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $t->string('creditor', 120);
            $t->decimal('financed_amount', 15, 2);
            $t->unsignedSmallInteger('installment_count');
            $t->decimal('installment_amount', 15, 2);
            $t->date('first_due_date');
            $t->decimal('interest_rate_am', 8, 4)->nullable(); // % a.m., opcional
            $t->string('amortization_system', 10)->nullable(); // price | sac
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('property_id');
        });

        Schema::create('property_financing_installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_financing_id')->constrained('property_financings')->cascadeOnDelete();
            $t->unsignedSmallInteger('number');
            $t->date('due_date');
            $t->decimal('amount', 15, 2);
            $t->string('status', 10)->default('open'); // open | paid
            $t->date('paid_at')->nullable();
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->boolean('linked_existing')->default(false);
            $t->unsignedBigInteger('linked_previous_category_id')->nullable();
            $t->foreign('linked_previous_category_id', 'pfi_prev_cat_fk')->references('id')->on('categories')->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['property_financing_id', 'number'], 'pfi_financing_number_unique');
            $t->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_financing_installments');
        Schema::dropIfExists('property_financings');
        Schema::dropIfExists('property_down_payments');
    }
};
