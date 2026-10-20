<?php

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\ReceiptDestination;
use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Services\ReceiptAccountAllocationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('receipt_destination_payments', 'settlement_transaction_id')) {
            Schema::table('receipt_destination_payments', function (Blueprint $table) {
                $table->foreignId('settlement_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('receipt_destination_payments', 'request_key')) {
            Schema::table('receipt_destination_payments', function (Blueprint $table) {
                $table->uuid('request_key')->nullable()->unique();
            });
        }

        // Restore the full receipt in the real bank and reserve its third-party shares
        // only in the virtual accounts. FITIDs and independent entries remain untouched.
        ReceiptDestination::whereNotNull('repasse_account_id')->orderBy('id')->chunkById(100, function ($parts) {
            foreach ($parts as $part) {
                DB::transaction(function () use ($part) {
                    Transaction::lockForUpdate()->findOrFail($part->receipt_id);
                    $part = ReceiptDestination::lockForUpdate()->findOrFail($part->id);
                    $target = BankAccount::withTrashed()->findOrFail($part->repasse_account_id);
                    $service = app(ReceiptAccountAllocationService::class);
                    $service->sync($part, $target);
                    foreach ($part->payments()->get() as $payment) {
                        $service->settle($payment);
                    }
                    $part->receipt->touch();
                });
            }
        });
        Cache::forget('banking.dashboard.summary');
    }

    public function down(): void
    {
        if (ReceiptDestination::whereNotNull('repasse_account_id')->exists() || DB::table('receipt_destination_payments')->whereNotNull('settlement_transaction_id')->exists()) {
            throw new RuntimeException('Remova os recebimentos e repasses vinculados antes de reverter o controle de obrigações.');
        }
        Schema::table('receipt_destination_payments', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn('request_key');
            $table->dropUnique(['settlement_transaction_id']);
            $table->dropConstrainedForeignId('settlement_transaction_id');
        });
    }
};
