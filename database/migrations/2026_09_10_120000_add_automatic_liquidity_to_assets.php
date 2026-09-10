<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('automatic_liquidity')->default(false)->after('liquidity');
            $table->foreignId('linked_bank_account_id')
                ->nullable()
                ->after('automatic_liquidity')
                ->constrained('bank_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_bank_account_id');
            $table->dropColumn('automatic_liquidity');
        });
    }
};
