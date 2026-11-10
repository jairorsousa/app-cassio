<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pagamentos de bens (entrada, parcelas, previstos) e a categoria de sistema "Aquisição de bem" (fora do resultado). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $t->string('status', 10)->default('paid'); // paid | planned
            $t->date('date')->nullable();
            $t->decimal('amount', 15, 2);
            $t->string('method', 10)->nullable(); // account | card
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $t->foreignId('credit_card_id')->nullable()->constrained('credit_cards')->nullOnDelete();
            $t->unsignedSmallInteger('installments')->default(1);
            $t->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['property_id', 'status']);
        });

        if (! DB::table('categories')->where('system_key', 'asset_purchase')->exists()) {
            DB::table('categories')->insert([
                'name' => 'Aquisição de bem', 'type' => 'transfer', 'status' => true,
                'is_system' => true, 'system_key' => 'asset_purchase',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_payments');
        DB::table('categories')->where('system_key', 'asset_purchase')->whereNotExists(fn ($q) => $q->from('transactions')->whereColumn('transactions.category_id', 'categories.id'))->delete();
    }
};
