<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 5: titular do cartão. Cartões antigos ficam com titular nulo ("Não informado") — nada é inferido do nome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->string('holder_type', 20)->nullable()->after('bank');
            $table->string('holder_name', 120)->nullable()->after('holder_type');
            $table->foreignId('holder_contact_id')->nullable()->after('holder_name')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holder_contact_id');
            $table->dropColumn(['holder_type', 'holder_name']);
        });
    }
};
