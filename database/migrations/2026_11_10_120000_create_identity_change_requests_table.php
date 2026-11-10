<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pedidos de troca de CPF e de exclusão de e-mail que aguardam a aprovação do administrador. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20); // document | email_remove
            $table->string('old_value', 200)->nullable();
            $table->string('new_value', 200)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'type']);
            $table->index(['contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_change_requests');
    }
};
