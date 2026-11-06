<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            DB::statement("ALTER TABLE writs MODIFY stage ENUM('monitoring', 'negotiation', 'pending', 'signature', 'paid', 'petitioning', 'awaiting_receipt', 'finalized', 'lost') NOT NULL DEFAULT 'negotiation'");
        }

        Schema::table('writs', function (Blueprint $table) {
            if (! Schema::hasColumn('writs', 'signature_at')) {
                $table->dateTime('signature_at')->nullable()->after('cession_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('writs')) {
            return;
        }

        DB::table('writs')
            ->where('stage', 'signature')
            ->update(['stage' => 'pending']);

        Schema::table('writs', function (Blueprint $table) {
            if (Schema::hasColumn('writs', 'signature_at')) {
                $table->dropColumn('signature_at');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE writs MODIFY stage ENUM('monitoring', 'negotiation', 'pending', 'paid', 'petitioning', 'awaiting_receipt', 'finalized', 'lost') NOT NULL DEFAULT 'negotiation'");
        }
    }
};
