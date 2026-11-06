<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Staging de extratos de corretora (CSV/XLSX/PDF). Nada aqui é financeiro até confirmação explícita. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broker_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->string('broker_name', 80);
            $table->string('file_name');
            $table->string('file_kind', 10); // csv, xlsx, pdf
            $table->string('file_path')->nullable(); // privado; apagado ao confirmar/descartar/expirar
            $table->char('file_hash', 64);
            $table->string('status', 20)->default('draft'); // draft, needs_review, confirmed, discarded, expired
            $table->string('text_status', 30)->nullable(); // pdf: text_extracted, no_text, extractor_unavailable
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('ai_suggestion')->nullable();
            $table->string('ai_provider', 40)->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('broker_statement_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('broker_statement_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->date('date')->nullable();
            $table->string('ticker', 20)->nullable();
            $table->string('kind', 30)->default('unknown');
            $table->string('source_type_label')->nullable();
            $table->decimal('quantity', 20, 6)->nullable();
            $table->decimal('unit_price', 20, 6)->nullable();
            $table->decimal('gross_amount', 15, 2)->nullable();
            $table->decimal('withholding_tax', 15, 2)->nullable();
            $table->decimal('fee_amount', 15, 2)->nullable();
            $table->decimal('net_amount', 15, 2)->nullable();
            $table->decimal('lending_fee_rate', 10, 6)->nullable();
            $table->string('external_id', 120)->nullable();
            $table->string('source_key', 120)->nullable();
            $table->string('status', 30)->default('pending'); // ready, error, duplicate, duplicate_candidate, pending_manual, excluded, imported
            $table->foreignId('duplicate_of_event_id')->nullable()->constrained('asset_income_events')->nullOnDelete();
            $table->boolean('force_import')->default(false);
            $table->json('messages')->nullable();
            $table->foreignId('income_event_id')->nullable()->constrained('asset_income_events')->nullOnDelete();
            $table->timestamps();
            $table->index(['import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_statement_rows');
        Schema::dropIfExists('broker_statement_imports');
    }
};
