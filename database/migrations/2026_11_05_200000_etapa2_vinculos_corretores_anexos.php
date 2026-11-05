<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2: vínculos opcionais do lançamento, corretores do recebimento de processo,
 * requisitório baixado pelo recebimento e anexos de comprovante.
 * Somente estrutura (colunas e tabelas novas, todas opcionais); nenhum dado existente é alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('link_process', 120)->nullable()->after('source_id');
            $table->foreignId('link_writ_id')->nullable()->after('link_process')->constrained('writs')->nullOnDelete();
            $table->foreignId('link_broker_id')->nullable()->after('link_writ_id')->constrained('brokers')->nullOnDelete();
            $table->foreignId('link_contact_id')->nullable()->after('link_broker_id')->constrained('contacts')->nullOnDelete();
        });

        Schema::table('process_receipts', function (Blueprint $table) {
            $table->foreignId('writ_id')->nullable()->after('broker_id')->constrained('writs')->nullOnDelete();
            $table->decimal('writ_amount', 15, 2)->nullable()->after('writ_id');
            $table->string('process_ref', 120)->nullable()->after('writ_amount');
        });

        Schema::create('process_receipt_brokers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_receipt_id')->constrained('process_receipts')->cascadeOnDelete();
            $table->foreignId('broker_id')->constrained('brokers')->restrictOnDelete();
            $table->string('base', 12)->default('fees');
            $table->string('mode', 12)->default('fixed');
            $table->decimal('rate_or_amount', 15, 2);
            $table->decimal('calculated_amount', 15, 2);
            $table->decimal('compensated_amount', 15, 2)->default(0);
            $table->foreignId('commission_id')->nullable()->constrained('broker_commissions')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('transaction_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_attachments');
        Schema::dropIfExists('process_receipt_brokers');
        Schema::table('process_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('writ_id');
            $table->dropColumn(['writ_amount', 'process_ref']);
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('link_writ_id');
            $table->dropConstrainedForeignId('link_broker_id');
            $table->dropConstrainedForeignId('link_contact_id');
            $table->dropColumn('link_process');
        });
    }
};
