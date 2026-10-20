<?php

use App\Domains\Brokers\Services\BrokerProfileService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BrokerProfileService::class)->linkLegacyProfiles();
    }

    public function down(): void
    {
        // Preserve contacts and ledger links: removing them could hide existing commissions again.
    }
};
