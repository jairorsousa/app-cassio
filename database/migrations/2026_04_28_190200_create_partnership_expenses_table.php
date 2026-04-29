<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partnership_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partnership_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('total_amount', 15, 2);
            $table->decimal('applied_percentage', 6, 3);
            $table->decimal('proportional_amount', 15, 2);
            $table->string('description');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['partnership_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partnership_expenses');
    }
};
