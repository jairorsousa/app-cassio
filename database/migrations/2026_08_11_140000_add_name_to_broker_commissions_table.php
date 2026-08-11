<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broker_commissions', function (Blueprint $table) {
            $table->string('name', 160)->nullable()->after('case_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('broker_commissions', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
