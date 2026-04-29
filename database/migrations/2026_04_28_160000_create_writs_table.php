<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('writs', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['rpv', 'precatorio']);
            $table->enum('stage', ['negotiation', 'pending', 'paid', 'petitioning', 'finalized'])
                ->default('negotiation');

            $table->string('process_number')->nullable();
            $table->string('court')->nullable();
            $table->string('debtor_entity')->nullable();
            $table->string('credit_nature')->nullable();

            $table->string('assignor_name')->nullable();
            $table->string('assignor_document', 30)->nullable();
            $table->string('assignor_contact')->nullable();
            $table->text('assignor_bank_data')->nullable();
            $table->string('assignor_lawyer')->nullable();

            $table->decimal('face_value', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('discount_percentage', 6, 3)->default(0);
            $table->decimal('estimated_receipt_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('estimated_months')->nullable();

            $table->decimal('actual_receipt_amount', 15, 2)->nullable();
            $table->date('paid_at')->nullable();
            $table->date('finalized_at')->nullable();

            $table->foreignId('source_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('destination_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('stage');
            $table->index('type');
            $table->index('debtor_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('writs');
    }
};
