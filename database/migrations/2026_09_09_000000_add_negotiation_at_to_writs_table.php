<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('writs', function (Blueprint $table) {
            $table->dateTime('negotiation_at')->nullable()->after('monitoring_at');
        });
    }

    public function down(): void
    {
        Schema::table('writs', function (Blueprint $table) {
            $table->dropColumn('negotiation_at');
        });
    }
};
