<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('interest_type', 16)->default('simple')->after('interest_rate_am');
        });

        Schema::table('receivables', function (Blueprint $table) {
            $table->string('interest_type', 16)->default('simple')->after('interest_rate_am');
            $table->string('late_interest_type', 16)->default('simple')->after('late_interest_rate_am');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('interest_type');
        });

        Schema::table('receivables', function (Blueprint $table) {
            $table->dropColumn(['interest_type', 'late_interest_type']);
        });
    }
};
