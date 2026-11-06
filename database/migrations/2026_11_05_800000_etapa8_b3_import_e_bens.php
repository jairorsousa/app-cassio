<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 8: estrutura para importar a posição B3 (snapshot agregado) e proventos recebidos (Midas),
 * com idempotência por chave de origem, e cadastro de Bens. Só estrutura; nenhum dado é inserido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('source', 40);              // b3_midas
            $t->date('base_date')->nullable();     // data-base da posição B3
            $t->string('status', 20)->default('preview'); // preview | confirmed
            $t->string('position_file_hash', 64)->nullable();
            $t->string('income_file_hash', 64)->nullable();
            $t->string('position_file_name')->nullable();
            $t->string('income_file_name')->nullable();
            $t->string('position_path')->nullable();
            $t->string('income_path')->nullable();
            $t->json('summary')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('b3_position_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('import_batch_id')->constrained('investment_import_batches')->cascadeOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->date('base_date');
            $t->string('ticker', 20);
            $t->string('product')->nullable();
            $t->string('origin', 40);              // custodia, custodia_fii, custodia_bdr, emprestimo_doador, custodia+emprestimo_doador
            $t->decimal('quantity', 18, 6);
            $t->decimal('quantity_custody', 18, 6)->nullable(); // null = fonte não separa
            $t->decimal('quantity_lent', 18, 6)->nullable();
            $t->decimal('average_price', 15, 4)->nullable();    // null = PM desconhecido (nunca zero)
            $t->string('average_price_status', 60)->nullable();
            $t->decimal('close_price', 15, 4)->nullable();
            $t->decimal('market_value', 15, 2);
            $t->text('institutions')->nullable();  // como veio da B3; não vira conta
            $t->string('source_key', 120)->unique();
            $t->timestamps();
            $t->index(['base_date', 'ticker']);
        });

        Schema::create('asset_income_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('import_batch_id')->nullable()->constrained('investment_import_batches')->nullOnDelete();
            $t->string('ticker', 20);
            $t->date('payment_date');
            $t->date('record_date')->nullable();
            $t->string('type', 30);                 // dividend, jcp, fii_income, lending_reimbursement, lending_fee, other
            $t->string('source_type_label', 40)->nullable(); // rótulo original (Dividendo, JCP, Rendimento, Reembolso)
            $t->decimal('quantity', 18, 6)->nullable();
            $t->decimal('gross_amount', 15, 2)->nullable(); // B3 não informa: fica nulo
            $t->decimal('withholding_tax', 15, 2)->nullable();
            $t->decimal('net_amount', 15, 2);
            $t->boolean('is_lending')->default(false);
            $t->string('lending_contract_ref', 80)->nullable(); // contrato da corretora (importação futura)
            $t->decimal('lending_fee_rate', 9, 4)->nullable();  // taxa % a.a. (importação futura)
            $t->string('broker_name', 120)->nullable();         // instituição como veio da fonte
            $t->string('asset_class_label', 40)->nullable();
            $t->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete(); // vazio: não cria Financeiro
            $t->string('source', 40)->default('manual');
            $t->string('source_file')->nullable();
            $t->string('source_key', 160)->unique();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['payment_date']);
            $t->index(['ticker', 'payment_date']);
        });

        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 30);                 // imovel, veiculo, terreno, participacao, outro
            $t->string('description');
            $t->string('identifier')->nullable();   // matrícula, placa/RENAVAM
            $t->date('acquired_at')->nullable();
            $t->decimal('acquisition_value', 15, 2)->nullable();
            $t->decimal('current_value', 15, 2)->nullable();
            $t->date('current_value_date')->nullable();
            $t->string('owner', 30)->nullable();    // cassio, luciane, ambos, empresa
            $t->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('active'); // active, sold
            $t->date('sold_at')->nullable();
            $t->decimal('sale_value', 15, 2)->nullable();
            $t->text('notes')->nullable();
            $t->string('attachment_path')->nullable();
            $t->string('attachment_original_name')->nullable();
            $t->string('attachment_mime', 120)->nullable();
            $t->unsignedBigInteger('attachment_size')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
        Schema::dropIfExists('asset_income_events');
        Schema::dropIfExists('b3_position_snapshots');
        Schema::dropIfExists('investment_import_batches');
    }
};
