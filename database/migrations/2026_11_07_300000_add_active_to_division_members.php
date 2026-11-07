<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Participante desativado: some das novas despesas, mas o histórico fica. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('division_members', fn (Blueprint $t) => $t->boolean('active')->default(true)->after('is_owner'));
    }

    public function down(): void
    {
        Schema::table('division_members', fn (Blueprint $t) => $t->dropColumn('active'));
    }
};
