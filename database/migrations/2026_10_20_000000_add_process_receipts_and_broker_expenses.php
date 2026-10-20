<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_commissions', function (Blueprint $table) {
            $table->boolean('manual_advances')->default(false);
        });
        Schema::create('process_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->unique()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('broker_id')->constrained('brokers')->restrictOnDelete();
            $table->decimal('fees_amount', 15, 2);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('broker_process_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained('brokers')->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('description', 200);
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('process_expense_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('broker_process_expenses')->restrictOnDelete();
            $table->foreignId('destination_id')->constrained('receipt_destinations')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['expense_id', 'destination_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_expense_recoveries');
        Schema::dropIfExists('broker_process_expenses');
        Schema::dropIfExists('process_receipts');
        Schema::table('broker_commissions', fn (Blueprint $table) => $table->dropColumn('manual_advances'));
    }
};
