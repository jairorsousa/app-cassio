<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->enum('type', ['checking', 'savings', 'investment', 'cash', 'repasse'])->default('checking')->change();
        });
        Schema::table('receipt_destinations', function (Blueprint $table) {
            $table->foreignId('repasse_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('allocation_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('bank_accounts')->where('type', 'repasse')->exists() || DB::table('receipt_destinations')->whereNotNull('allocation_transaction_id')->exists()) {
            throw new RuntimeException('Remova as distribuições e altere o tipo das contas de repasse antes de reverter esta migração.');
        }
        Schema::table('receipt_destinations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repasse_account_id');
            $table->dropUnique(['allocation_transaction_id']);
            $table->dropConstrainedForeignId('allocation_transaction_id');
        });
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->enum('type', ['checking', 'savings', 'investment', 'cash'])->default('checking')->change();
        });
    }
};
