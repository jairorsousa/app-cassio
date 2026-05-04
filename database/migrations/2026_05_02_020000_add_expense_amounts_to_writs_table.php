<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('writs', function (Blueprint $table) {
            $table->decimal('notary_expenses_amount', 15, 2)->default(0)->after('paid_amount');
            $table->decimal('other_expenses_amount', 15, 2)->default(0)->after('notary_expenses_amount');
        });
    }

    public function down(): void
    {
        Schema::table('writs', function (Blueprint $table) {
            $table->dropColumn(['notary_expenses_amount', 'other_expenses_amount']);
        });
    }
};
