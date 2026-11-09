<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Regime do resultado: padrão passa a ser Competência para novos usuários. Não altera o valor já salvo de ninguém. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('financial_regime', 12)->default('accrual')->change());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('financial_regime', 12)->default('cash')->change());
    }
};
