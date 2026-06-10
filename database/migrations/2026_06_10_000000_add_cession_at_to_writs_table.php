<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('writs', function (Blueprint $table) {
            $table->dateTime('cession_at')->nullable()->after('actual_receipt_amount');
        });
    }

    public function down(): void
    {
        Schema::table('writs', function (Blueprint $table) {
            $table->dropColumn('cession_at');
        });
    }
};
