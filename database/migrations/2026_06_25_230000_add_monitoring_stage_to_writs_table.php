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
            DB::statement("ALTER TABLE writs MODIFY stage ENUM('monitoring', 'negotiation', 'pending', 'paid', 'petitioning', 'awaiting_receipt', 'finalized', 'lost') NOT NULL DEFAULT 'negotiation'");
        }

        Schema::table('writs', function (Blueprint $table) {
            if (! Schema::hasColumn('writs', 'monitoring_at')) {
                $table->dateTime('monitoring_at')->nullable()->after('estimated_months');
            }

            if (! Schema::hasColumn('writs', 'google_calendar_monitoring_event_id')) {
                $table->string('google_calendar_monitoring_event_id')->nullable()->after('monitoring_at');
                $table->string('google_calendar_monitoring_event_link')->nullable()->after('google_calendar_monitoring_event_id');
                $table->timestamp('google_calendar_monitoring_synced_at')->nullable()->after('google_calendar_monitoring_event_link');
                $table->text('google_calendar_monitoring_sync_error')->nullable()->after('google_calendar_monitoring_synced_at');
                $table->index('google_calendar_monitoring_event_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('writs')) {
            return;
        }

        DB::table('writs')
            ->where('stage', 'monitoring')
            ->update(['stage' => 'negotiation']);

        Schema::table('writs', function (Blueprint $table) {
            if (Schema::hasColumn('writs', 'google_calendar_monitoring_event_id')) {
                $table->dropIndex(['google_calendar_monitoring_event_id']);
                $table->dropColumn([
                    'google_calendar_monitoring_event_id',
                    'google_calendar_monitoring_event_link',
                    'google_calendar_monitoring_synced_at',
                    'google_calendar_monitoring_sync_error',
                ]);
            }

            if (Schema::hasColumn('writs', 'monitoring_at')) {
                $table->dropColumn('monitoring_at');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE writs MODIFY stage ENUM('negotiation', 'pending', 'paid', 'petitioning', 'awaiting_receipt', 'finalized', 'lost') NOT NULL DEFAULT 'negotiation'");
        }
    }
};
