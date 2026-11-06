<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Etapa 7: preferências do Dashboard por usuário (ordem, visibilidade, tipo de gráfico, período). Só estrutura. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'dashboard_preferences')) {
            Schema::table('users', fn (Blueprint $table) => $table->json('dashboard_preferences')->nullable()->after('financial_regime'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'dashboard_preferences')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('dashboard_preferences'));
        }
    }
};
