<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transmission_batches', function (Blueprint $t) {
            $t->foreignId('retry_of_id')->nullable()->after('created_by')->constrained('transmission_batches')->nullOnDelete();
            $t->timestamp('paused_at')->nullable()->after('status');
            $t->timestamp('cancelled_at')->nullable()->after('paused_at');
            $t->foreignId('controlled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $t->index(['created_by', 'id']);
        });
        Schema::table('transmission_recipients', function (Blueprint $t) {
            $t->string('dispatch_token', 40)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('transmission_recipients', fn (Blueprint $t) => $t->dropColumn('dispatch_token'));
        Schema::table('transmission_batches', function (Blueprint $t) {
            $t->dropConstrainedForeignId('retry_of_id');
            $t->dropConstrainedForeignId('controlled_by');
            $t->dropIndex(['created_by', 'id']);
            $t->dropColumn(['paused_at', 'cancelled_at']);
        });
    }
};
