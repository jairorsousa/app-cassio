<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('writs')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE writs MODIFY stage ENUM('negotiation', 'pending', 'paid', 'petitioning', 'awaiting_receipt', 'finalized') NOT NULL DEFAULT 'negotiation'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('writs')) {
            return;
        }

        DB::table('writs')
            ->where('stage', 'awaiting_receipt')
            ->update(['stage' => 'petitioning']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE writs MODIFY stage ENUM('negotiation', 'pending', 'paid', 'petitioning', 'finalized') NOT NULL DEFAULT 'negotiation'");
        }
    }
};
