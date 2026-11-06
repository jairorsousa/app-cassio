<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALL = ['dashboard', 'financeiro', 'investimentos', 'requisitorio', 'corretores', 'contatos', 'sociedade', 'transmissao'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false)->after('password');
            $t->string('status', 20)->default('active')->after('is_admin');
            $t->json('modules')->nullable()->after('status'); // null = nenhum módulo
            $t->timestamp('blocked_at')->nullable()->after('modules');
        });
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->json('modules');
            $t->char('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('email');
        });

        // Backfill explícito: usuários que já existem continuam com todos os módulos; só o id 1 (Cassio) é administrador.
        DB::table('users')->update(['modules' => json_encode(self::ALL), 'status' => 'active']);
        DB::table('users')->where('id', 1)->update(['is_admin' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'status', 'modules', 'blocked_at']));
    }
};
