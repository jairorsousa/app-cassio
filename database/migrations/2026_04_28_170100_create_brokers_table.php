<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brokers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('document', 30)->nullable(); // CPF/CNPJ
            $table->string('rg', 30)->nullable();
            $table->date('birth_date')->nullable();

            // Contato
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();

            // Dados bancários
            $table->string('bank_name')->nullable();
            $table->string('bank_agency', 20)->nullable();
            $table->string('bank_account', 30)->nullable();
            $table->string('bank_account_type', 20)->nullable();
            $table->string('pix_key')->nullable();

            $table->boolean('status')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brokers');
    }
};
