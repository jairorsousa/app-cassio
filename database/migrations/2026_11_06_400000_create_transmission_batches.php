<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmission_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('labels')->nullable();
            $t->text('body');
            $t->string('media_path')->nullable();
            $t->string('media_name')->nullable();
            $t->string('media_mime', 100)->nullable();
            $t->string('status', 30)->default('queued')->index();
            $t->unsignedInteger('recipients_count')->default(0);
            $t->unsignedInteger('duplicates_omitted')->default(0);
            $t->unsignedInteger('invalid_omitted')->default(0);
            $t->timestamp('media_removed_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('transmission_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('batch_id')->constrained('transmission_batches')->cascadeOnDelete();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('name')->nullable();
            $t->text('phone');
            $t->char('phone_hash', 64);
            $t->json('labels')->nullable();
            $t->unsignedInteger('position')->default(0);
            $t->string('status', 20)->default('pending')->index();
            $t->string('error', 500)->nullable();
            $t->string('external_message_id', 100)->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('attempted_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['batch_id', 'phone_hash']);
            $t->index('phone_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmission_recipients');
        Schema::dropIfExists('transmission_batches');
    }
};
