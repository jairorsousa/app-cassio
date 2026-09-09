<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('institution', 120)->nullable();
            $table->date('maturity_date')->nullable();
            $table->string('liquidity', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn(['institution', 'maturity_date', 'liquidity']));
    }
};
