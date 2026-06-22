<?php

namespace App\Providers;

use App\Domains\Banking\Models\Transaction;
use App\Domains\Banking\Observers\TransactionObserver;
use App\Domains\Brokers\Events\BrokerAdvancePaid;
use App\Domains\Brokers\Events\BrokerCommissionPaid;
use App\Domains\Brokers\Listeners\CreateExpenseOnAdvancePaid;
use App\Domains\Brokers\Listeners\CreateExpenseOnCommissionPaid;
use App\Domains\Brokers\Models\BrokerAdvance;
use App\Domains\Brokers\Models\BrokerCommissionSettlement;
use App\Domains\Brokers\Observers\BrokerAdvanceObserver;
use App\Domains\Brokers\Observers\BrokerCommissionSettlementObserver;
use App\Domains\Investments\Events\AssetOperationRegistered;
use App\Domains\Investments\Events\DividendReceived;
use App\Domains\Investments\Listeners\CreateIncomeOnDividend;
use App\Domains\Investments\Listeners\CreateTransactionOnOperation;
use App\Domains\Investments\Models\AssetDividend;
use App\Domains\Investments\Models\AssetOperation;
use App\Domains\Investments\Models\AssetPosition;
use App\Domains\Investments\Observers\AssetDividendObserver;
use App\Domains\Investments\Observers\AssetOperationObserver;
use App\Domains\Investments\Observers\AssetPositionObserver;
use App\Domains\Partnership\Events\PartnershipContributionMade;
use App\Domains\Partnership\Events\PartnershipDistributionReceived;
use App\Domains\Partnership\Events\PartnershipExpenseRecorded;
use App\Domains\Partnership\Listeners\CreateExpenseOnContribution;
use App\Domains\Partnership\Listeners\CreateExpenseOnPartnershipExpense;
use App\Domains\Partnership\Listeners\CreateIncomeOnDistribution;
use App\Domains\Partnership\Models\PartnershipContribution;
use App\Domains\Partnership\Models\PartnershipDistribution;
use App\Domains\Partnership\Models\PartnershipExpense;
use App\Domains\Partnership\Observers\PartnershipContributionObserver;
use App\Domains\Partnership\Observers\PartnershipDistributionObserver;
use App\Domains\Partnership\Observers\PartnershipExpenseObserver;
use App\Domains\Writs\Events\WritMovedToFinalized;
use App\Domains\Writs\Events\WritMovedToPaid;
use App\Domains\Writs\Listeners\CreateExpenseOnPaid;
use App\Domains\Writs\Listeners\CreateIncomeOnFinalized;
use App\Domains\Writs\Models\Writ;
use App\Domains\Writs\Observers\WritObserver;
use App\Listeners\LogAuthenticationActivity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);

            if (str_starts_with($appUrl, 'https://')) {
                URL::forceScheme('https');
            }
        }

        Event::listen(Login::class, [LogAuthenticationActivity::class, 'handleLogin']);
        Event::listen(Failed::class, [LogAuthenticationActivity::class, 'handleFailed']);
        Event::listen(Lockout::class, [LogAuthenticationActivity::class, 'handleLockout']);
        Event::listen(Logout::class, [LogAuthenticationActivity::class, 'handleLogout']);

        Transaction::observe(TransactionObserver::class);

        Event::listen(WritMovedToPaid::class, CreateExpenseOnPaid::class);
        Event::listen(WritMovedToFinalized::class, CreateIncomeOnFinalized::class);

        Event::listen(BrokerAdvancePaid::class, CreateExpenseOnAdvancePaid::class);
        Event::listen(BrokerCommissionPaid::class, CreateExpenseOnCommissionPaid::class);

        AssetOperation::observe(AssetOperationObserver::class);
        AssetDividend::observe(AssetDividendObserver::class);
        Event::listen(AssetOperationRegistered::class, CreateTransactionOnOperation::class);
        Event::listen(DividendReceived::class, CreateIncomeOnDividend::class);

        PartnershipContribution::observe(PartnershipContributionObserver::class);
        PartnershipExpense::observe(PartnershipExpenseObserver::class);
        PartnershipDistribution::observe(PartnershipDistributionObserver::class);
        Event::listen(PartnershipContributionMade::class, CreateExpenseOnContribution::class);
        Event::listen(PartnershipExpenseRecorded::class, CreateExpenseOnPartnershipExpense::class);
        Event::listen(PartnershipDistributionReceived::class, CreateIncomeOnDistribution::class);

        // Hooks de invalidação do snapshot consolidado para mutações
        // que não criam transactions (ex: cotações, edição de writs, settlements).
        AssetPosition::observe(AssetPositionObserver::class);
        Writ::observe(WritObserver::class);
        BrokerAdvance::observe(BrokerAdvanceObserver::class);
        BrokerCommissionSettlement::observe(BrokerCommissionSettlementObserver::class);
    }
}
