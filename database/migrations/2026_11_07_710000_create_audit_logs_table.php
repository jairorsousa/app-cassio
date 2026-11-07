<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Log de auditoria: quem, quando, o quê, antes e depois. Só inclusão (nunca editado pelo sistema). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('user_name', 160)->nullable();
            $table->string('action', 20)->index();
            $table->string('entity_type', 120)->nullable()->index();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_label', 200)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('url', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
