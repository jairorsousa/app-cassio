<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmission_suppressions', function (Blueprint $t) {
            $t->id();
            $t->char('phone_hash', 64)->index();
            $t->text('phone');
            $t->string('name')->nullable();
            $t->string('reason', 300)->nullable();
            $t->string('source', 30)->default('manual');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('suppressed_at');
            $t->timestamp('restored_at')->nullable();
            $t->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('transmission_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind', 10); // once | weekly
            $t->string('status', 20)->default('active')->index(); // active | paused | cancelled | done | failed
            $t->json('labels');
            $t->text('body');
            $t->string('media_path')->nullable();
            $t->string('media_name')->nullable();
            $t->string('media_mime', 100)->nullable();
            $t->timestamp('first_run_at');
            $t->string('first_period', 10)->nullable(); // morning | afternoon (semanal)
            $t->timestamp('next_run_at')->nullable()->index();
            $t->unsignedInteger('runs_count')->default(0);
            $t->string('last_week_key', 10)->nullable();
            $t->foreignId('last_batch_id')->nullable()->constrained('transmission_batches')->nullOnDelete();
            $t->string('last_error', 500)->nullable();
            $t->timestamp('last_run_at')->nullable();
            $t->timestamps();
        });
        Schema::table('transmission_batches', function (Blueprint $t) {
            $t->unsignedInteger('suppressed_omitted')->default(0)->after('invalid_omitted');
            $t->foreignId('schedule_id')->nullable()->after('retry_of_id')->constrained('transmission_schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transmission_batches', function (Blueprint $t) {
            $t->dropConstrainedForeignId('schedule_id');
            $t->dropColumn('suppressed_omitted');
        });
        Schema::dropIfExists('transmission_schedules');
        Schema::dropIfExists('transmission_suppressions');
    }
};
