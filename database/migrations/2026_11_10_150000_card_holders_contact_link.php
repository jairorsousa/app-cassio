<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Titular de cartão pode ser ligado ao contato da pessoa (identidade); o rótulo curto do titular continua o mesmo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_holders', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('name')->constrained('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('card_holders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
