<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Módulo Divisões (estilo Splitwise). Só cria tabelas novas; não altera dados existentes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('division_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->string('type', 20); // sociedade | familia | amigos
            $t->string('status', 20)->default('approved'); // pending | approved | rejected
            $t->boolean('requires_approval')->default(true); // despesas de participantes precisam de aprovação
            $t->text('notes')->nullable();
            $t->string('rejection_reason')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('division_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('division_groups')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('email', 190)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_owner')->default(false); // o administrador (Cassio)
            $t->timestamps();
            $t->index(['group_id', 'email']);
        });
        Schema::create('division_expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('division_groups')->cascadeOnDelete();
            $t->string('description', 200);
            $t->string('category', 40)->default('outros');
            $t->date('date');
            $t->decimal('amount', 15, 2);
            $t->foreignId('paid_by_member_id')->constrained('division_members');
            $t->string('split_mode', 10); // equal | percent | amount | shares
            $t->string('status', 20)->default('approved'); // pending | approved | rejected
            $t->uuid('installment_group_id')->nullable();
            $t->unsignedSmallInteger('installment_number')->nullable();
            $t->unsignedSmallInteger('installment_total')->nullable();
            $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->string('receipt_path')->nullable();
            $t->string('receipt_original_name')->nullable();
            $t->string('receipt_mime', 100)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['group_id', 'status', 'date']);
        });
        Schema::create('division_expense_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained('division_expenses')->cascadeOnDelete();
            $t->foreignId('member_id')->constrained('division_members')->cascadeOnDelete();
            $t->decimal('input', 15, 4)->nullable(); // % / valor / cotas informados
            $t->decimal('amount', 15, 2);
            $t->timestamps();
        });
        Schema::create('division_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('division_groups')->cascadeOnDelete();
            $t->foreignId('from_member_id')->constrained('division_members');
            $t->foreignId('to_member_id')->constrained('division_members');
            $t->decimal('amount', 15, 2);
            $t->date('date');
            $t->text('notes')->nullable();
            $t->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('division_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('division_groups')->cascadeOnDelete();
            $t->foreignId('expense_id')->nullable()->constrained('division_expenses')->cascadeOnDelete();
            $t->foreignId('settlement_id')->nullable()->constrained('division_settlements')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 40);
            $t->json('details')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('division_audits');
        Schema::dropIfExists('division_settlements');
        Schema::dropIfExists('division_expense_shares');
        Schema::dropIfExists('division_expenses');
        Schema::dropIfExists('division_members');
        Schema::dropIfExists('division_groups');
    }
};
