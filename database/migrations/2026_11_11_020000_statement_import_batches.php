<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename')->nullable();
            $table->string('source_label')->nullable();
            $table->string('invoice_mode', 16)->nullable(); // auto|fixed
            $table->string('invoice_month', 7)->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('ofx_fitid')
                ->constrained('statement_import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });
        Schema::dropIfExists('statement_import_batches');
    }
};
