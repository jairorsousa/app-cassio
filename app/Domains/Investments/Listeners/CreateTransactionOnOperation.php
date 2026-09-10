<?php

namespace App\Domains\Investments\Listeners;

use App\Domains\Investments\Events\AssetOperationRegistered;
use App\Domains\Investments\Services\InvestmentBankSyncService;

class CreateTransactionOnOperation
{
    public function __construct(private InvestmentBankSyncService $bankSync) {}

    public function handle(AssetOperationRegistered $event): void
    {
        $this->bankSync->sync($event->operation);
    }
}
