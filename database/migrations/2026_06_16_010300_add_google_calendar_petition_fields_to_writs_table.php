<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('writs', function (Blueprint $table) {
            $table->string('google_calendar_petition_event_id')->nullable()->after('google_calendar_sync_error');
            $table->string('google_calendar_petition_event_link')->nullable()->after('google_calendar_petition_event_id');
            $table->timestamp('google_calendar_petition_synced_at')->nullable()->after('google_calendar_petition_event_link');
            $table->text('google_calendar_petition_sync_error')->nullable()->after('google_calendar_petition_synced_at');

            $table->index('google_calendar_petition_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('writs', function (Blueprint $table) {
            $table->dropIndex(['google_calendar_petition_event_id']);
            $table->dropColumn([
                'google_calendar_petition_event_id',
                'google_calendar_petition_event_link',
                'google_calendar_petition_synced_at',
                'google_calendar_petition_sync_error',
            ]);
        });
    }
};
