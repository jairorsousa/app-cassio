<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Etapa 1: saldo inicial datado, conta vinculada, marcação de testes, categoria de sistema e regime. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->date('initial_balance_date')->nullable()->after('initial_balance');
            $table->foreignId('linked_account_id')->nullable()->after('type')->constrained('bank_accounts')->nullOnDelete();
        });
        foreach (['transactions', 'contacts', 'receivables'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('is_test')->default(false)->index();
            });
        }
        Schema::table('categories', function (Blueprint $table) {
            $table->string('type', 20)->change();
            $table->boolean('is_system')->default(false);
            $table->string('system_key', 40)->nullable()->unique();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('financial_regime', 12)->default('cash');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('financial_regime'));
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['system_key']);
            $table->dropColumn(['is_system', 'system_key']);
        });
        foreach (['transactions', 'contacts', 'receivables'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['is_test']);
                $table->dropColumn('is_test');
            });
        }
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_account_id');
            $table->dropColumn('initial_balance_date');
        });
    }
};
