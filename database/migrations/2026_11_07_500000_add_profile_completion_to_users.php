<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Complete seu cadastro": vínculo do usuário convidado com o Contato e data em que completou. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('contact_id')->nullable()->index();
            $t->timestamp('profile_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['contact_id']);
            $t->dropColumn(['contact_id', 'profile_completed_at']);
        });
    }
};
