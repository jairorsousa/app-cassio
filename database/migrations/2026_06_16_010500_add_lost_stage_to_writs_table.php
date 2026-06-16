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
            DB::statement("ALTER TABLE writs MODIFY stage ENUM('negotiation', 'pending', 'paid', 'petitioning', 'awaiting_receipt', 'finalized', 'lost') NOT NULL DEFAULT 'negotiation'");
        }

        Schema::table('writs', function (Blueprint $table) {
            if (! Schema::hasColumn('writs', 'lost_reason')) {
                $table->text('lost_reason')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('writs', 'lost_at')) {
                $table->timestamp('lost_at')->nullable()->after('lost_reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('writs')) {
            return;
        }

        DB::table('writs')
            ->where('stage', 'lost')
            ->update(['stage' => 'negotiation']);

        Schema::table('writs', function (Blueprint $table) {
            if (Schema::hasColumn('writs', 'lost_at')) {
                $table->dropColumn('lost_at');
            }

            if (Schema::hasColumn('writs', 'lost_reason')) {
                $table->dropColumn('lost_reason');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE writs MODIFY stage ENUM('negotiation', 'pending', 'paid', 'petitioning', 'awaiting_receipt', 'finalized') NOT NULL DEFAULT 'negotiation'");
        }
    }
};
