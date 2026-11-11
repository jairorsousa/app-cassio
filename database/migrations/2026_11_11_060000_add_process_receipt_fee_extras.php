<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_receipts', function (Blueprint $table) {
            $table->boolean('is_extra_only')->default(false)->after('fees_amount');
        });

        Schema::create('process_receipt_fee_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_receipt_id')->constrained('process_receipts')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('description', 200)->nullable();
            $table->decimal('amount', 15, 2);
            $table->boolean('in_commission_base')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_receipt_fee_items');
        Schema::table('process_receipts', function (Blueprint $table) {
            $table->dropColumn('is_extra_only');
        });
    }
};
