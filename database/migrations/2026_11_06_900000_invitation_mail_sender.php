<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_sender_settings', function (Blueprint $t) {
            $t->id();
            $t->string('username', 190);
            $t->string('host', 190);
            $t->unsignedSmallInteger('port');
            $t->string('encryption', 20)->default('tls');
            $t->text('password')->nullable(); // encrypted cast; never plaintext in app code paths
            $t->timestamp('password_set_at')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('user_invitations', function (Blueprint $t) {
            $t->string('email_delivery_status', 20)->nullable()->after('accepted_user_id');
            $t->char('email_dispatch_token', 64)->nullable()->after('email_delivery_status');
            $t->unsignedSmallInteger('email_attempts')->default(0)->after('email_dispatch_token');
            $t->timestamp('email_queued_at')->nullable()->after('email_attempts');
            $t->timestamp('email_sent_at')->nullable()->after('email_queued_at');
            $t->timestamp('email_failed_at')->nullable()->after('email_sent_at');
            $t->string('email_last_error', 190)->nullable()->after('email_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $t) {
            $t->dropColumn([
                'email_delivery_status',
                'email_dispatch_token',
                'email_attempts',
                'email_queued_at',
                'email_sent_at',
                'email_failed_at',
                'email_last_error',
            ]);
        });
        Schema::dropIfExists('mail_sender_settings');
    }
};
