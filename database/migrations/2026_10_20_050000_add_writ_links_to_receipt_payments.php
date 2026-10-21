<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipt_destination_payments', function (Blueprint $table) {
            $table->foreignId('writ_income_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->decimal('writ_amount', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('receipt_destination_payments')->whereNotNull('writ_income_id')->exists()) {
            throw new RuntimeException('Desvincule os requisitórios dos repasses antes de reverter esta migração.');
        }
        Schema::table('receipt_destination_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('writ_income_id');
            $table->dropColumn('writ_amount');
        });
    }
};
