<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->decimal('interest_rate_am', 8, 4)->default(0)->after('notes');
            $table->decimal('penalty_pct', 8, 4)->default(0)->after('interest_rate_am');
            $table->decimal('late_interest_rate_am', 8, 4)->default(0)->after('penalty_pct');
            $table->date('interest_start_date')->nullable()->after('late_interest_rate_am');
        });

        Schema::table('receivable_settlements', function (Blueprint $table) {
            $table->decimal('principal_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('interest_amount', 15, 2)->default(0)->after('principal_amount');
            $table->decimal('penalty_amount', 15, 2)->default(0)->after('interest_amount');
        });

        // Registros antigos: a baixa inteira era principal; encargos padrão zero.
        DB::table('receivable_settlements')->orderBy('id')->update([
            'principal_amount' => DB::raw('amount'),
            'interest_amount' => 0,
            'penalty_amount' => 0,
        ]);
        DB::table('receivables')->whereNull('interest_start_date')->update([
            'interest_start_date' => DB::raw('date'),
            'interest_rate_am' => 0,
            'penalty_pct' => 0,
            'late_interest_rate_am' => 0,
        ]);

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('disbursement_transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->string('description');
            $table->decimal('principal', 15, 2);
            $table->date('date');
            $table->unsignedSmallInteger('installment_count')->default(1);
            $table->date('first_due_date')->nullable();
            $table->decimal('interest_rate_am', 8, 4)->default(0);
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->boolean('is_test')->default(false);
            $table->timestamps();
        });

        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->decimal('amount', 15, 2);
            $table->date('due_date')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('loan_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_installment_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('batch_key')->index();
            $table->decimal('amount', 15, 2);
            $table->decimal('principal_amount', 15, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->date('date');
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('transaction_created')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_settlements');
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
        Schema::table('receivable_settlements', function (Blueprint $table) {
            $table->dropColumn(['principal_amount', 'interest_amount', 'penalty_amount']);
        });
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropColumn(['interest_rate_am', 'penalty_pct', 'late_interest_rate_am', 'interest_start_date']);
        });
    }
};
