<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_quotes', function (Blueprint $table) {
            $table->timestamp('market_time')->nullable()->after('price'); // horário da cotação na fonte (plano gratuito: atraso ~30 min)
            $table->string('source', 30)->nullable()->after('market_time');
        });
    }

    public function down(): void
    {
        Schema::table('asset_quotes', function (Blueprint $table) {
            $table->dropColumn(['market_time', 'source']);
        });
    }
};
