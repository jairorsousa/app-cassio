<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('writ_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->date('date');
            $table->string('repayment_mode', 20);
            $table->string('process_reference')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('request_key')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('receivable_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receivable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->decimal('amount', 15, 2);
            $table->date('due_date')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('receivable_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receivable_installment_id')->constrained()->restrictOnDelete();
            $table->uuid('batch_key')->index();
            $table->string('kind', 20);
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('transaction_created')->default(false);
            $table->foreignId('destination_id')->nullable()->constrained('receipt_destinations')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::table('receipt_destinations', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('receipt_destinations', fn (Blueprint $table) => $table->dropConstrainedForeignId('contact_id'));
        Schema::dropIfExists('receivable_settlements');
        Schema::dropIfExists('receivable_installments');
        Schema::dropIfExists('receivables');
    }
};
