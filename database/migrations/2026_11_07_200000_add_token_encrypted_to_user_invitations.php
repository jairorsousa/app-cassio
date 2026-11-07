<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Guarda o token do convite criptografado (Crypt) para mostrar o link atual. Convites antigos ficam só com o hash. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', fn (Blueprint $t) => $t->text('token_encrypted')->nullable()->after('token_hash'));
    }

    public function down(): void
    {
        Schema::table('user_invitations', fn (Blueprint $t) => $t->dropColumn('token_encrypted'));
    }
};
