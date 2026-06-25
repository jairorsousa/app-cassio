<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brokers', function (Blueprint $table) {
            $table->foreignId('contact_id')
                ->nullable()
                ->after('id')
                ->constrained('contacts')
                ->nullOnDelete();

            $table->unique('contact_id');
        });

        Schema::create('broker_commission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->constrained('brokers')->cascadeOnDelete();
            $table->foreignId('commission_id')->constrained('broker_commissions')->cascadeOnDelete();
            $table->date('paid_at');
            $table->decimal('amount', 15, 2);
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['broker_id', 'paid_at']);
            $table->index(['commission_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_commission_payments');

        Schema::table('brokers', function (Blueprint $table) {
            $table->dropUnique(['contact_id']);
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
