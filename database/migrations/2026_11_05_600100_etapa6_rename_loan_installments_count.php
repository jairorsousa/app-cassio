<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn("loans", "installments") && ! Schema::hasColumn("loans", "installment_count")) {
            Schema::table("loans", function (Blueprint $table) {
                $table->renameColumn("installments", "installment_count");
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn("loans", "installment_count") && ! Schema::hasColumn("loans", "installments")) {
            Schema::table("loans", function (Blueprint $table) {
                $table->renameColumn("installment_count", "installments");
            });
        }
    }
};
