<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['income', 'expense', 'transfer', 'invoice_payment']);
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'settled'])->default('settled');

            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_card_invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('related_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->uuid('installment_group_id')->nullable();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installment_total')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('date');
            $table->index('bank_account_id');
            $table->index('credit_card_id');
            $table->index('credit_card_invoice_id');
            $table->index(['source_type', 'source_id']);
            $table->index('installment_group_id');
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
