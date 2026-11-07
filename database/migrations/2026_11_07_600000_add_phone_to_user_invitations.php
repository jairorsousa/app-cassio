<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Celular opcional informado no convite (E.164), usado para pré-preencher o "Complete seu cadastro". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', fn (Blueprint $t) => $t->string('phone', 20)->nullable()->after('email'));
    }

    public function down(): void
    {
        Schema::table('user_invitations', fn (Blueprint $t) => $t->dropColumn('phone'));
    }
};
