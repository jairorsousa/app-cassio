<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 3 (ajuste): distingue Fundo (aplicação automática) de Investimento.
 * No MySQL amplia o ENUM; no SQLite (testes) a coluna já é string livre.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE bank_accounts MODIFY COLUMN type ENUM('checking', 'savings', 'investment', 'fund', 'cash', 'repasse') NOT NULL DEFAULT 'checking'");
        }

        DB::table('bank_accounts')
            ->where('id', 10)
            ->where('type', 'investment')
            ->update(['type' => 'fund']);

        DB::table('bank_accounts')
            ->where('type', 'investment')
            ->where('name', 'like', '%Fundo%aplica__o autom_tica%')
            ->update(['type' => 'fund']);
    }

    public function down(): void
    {
        DB::table('bank_accounts')
            ->where('type', 'fund')
            ->update(['type' => 'investment']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bank_accounts MODIFY COLUMN type ENUM('checking', 'savings', 'investment', 'cash', 'repasse') NOT NULL DEFAULT 'checking'");
        }
    }
};
