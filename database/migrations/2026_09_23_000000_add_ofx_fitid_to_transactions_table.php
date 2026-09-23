<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('ofx_fitid')->nullable()->after('source_id');
            $table->unique(['bank_account_id', 'ofx_fitid']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['bank_account_id', 'ofx_fitid']);
            $table->dropColumn('ofx_fitid');
        });
    }
};
