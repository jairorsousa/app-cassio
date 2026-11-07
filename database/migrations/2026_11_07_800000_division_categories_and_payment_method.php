<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('division_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('label', 80);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::table('division_expenses', function (Blueprint $table) {
            $table->string('payment_method', 10)->nullable()->after('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('division_expenses', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
        Schema::dropIfExists('division_categories');
    }
};
