<?php

namespace App\Domains\Banking\Models;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Models\Writ;
use App\Support\SimpleInterest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Receivable extends Model
{
    use \App\Support\NormalizesProcessNumbers;

    public const PROCESS_NUMBER_FIELDS = ['process_reference'];

    use LogsActivity;
    use \App\Support\HidesTestRecords;

    protected $fillable = ['is_test', 'contact_id', 'writ_id', 'description', 'date', 'repayment_mode', 'process_reference', 'notes', 'request_key', 'interest_rate_am', 'penalty_pct', 'late_interest_rate_am', 'interest_start_date'];

    protected $casts = ['is_test' => 'boolean', 'date' => 'date', 'interest_start_date' => 'date', 'interest_rate_am' => 'float', 'penalty_pct' => 'float', 'late_interest_rate_am' => 'float'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('receivables');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /** @return BelongsTo<Writ, $this> */
    public function writ(): BelongsTo
    {
        return $this->belongsTo(Writ::class)->withTrashed();
    }

    /** @return HasMany<ReceivableInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(ReceivableInstallment::class)->orderBy('number');
    }

    /** @return HasManyThrough<ReceivableSettlement, ReceivableInstallment, $this> */
    public function settlements(): HasManyThrough
    {
        return $this->hasManyThrough(ReceivableSettlement::class, ReceivableInstallment::class);
    }

    public function amountCents(): int
    {
        return $this->installments->sum(fn ($part) => $part->amountCents());
    }

    /** Principal em aberto (sem encargos). Mantido para rateio/despesa própria. */
    public function remainingCents(): int
    {
        return $this->installments->sum(fn ($part) => $part->remainingCents());
    }

    /** @return list<array{date:string,principal:int,interest:int,penalty:int}> */
    public function settlementChargeRows(): array
    {
        return $this->settlements->map(fn ($row) => [
            'date' => $row->date->toDateString(),
            'principal' => (int) round((float) ($row->principal_amount ?? $row->amount) * 100),
            'interest' => (int) round((float) ($row->interest_amount ?? 0) * 100),
            'penalty' => (int) round((float) ($row->penalty_amount ?? 0) * 100),
        ])->all();
    }

    public function dueDateForCharges(): ?string
    {
        $open = $this->installments->first(fn ($part) => $part->remainingCents() > 0);

        return $open?->due_date?->toDateString();
    }

    public function interestStart(): string
    {
        return ($this->interest_start_date ?? $this->date)->toDateString();
    }

    public function balanceAsOf(?string $asOf = null): array
    {
        $asOf ??= \App\Support\BusinessDate::today();

        return SimpleInterest::balance(
            $this->amountCents(),
            $this->interestStart(),
            $this->dueDateForCharges(),
            (float) ($this->interest_rate_am ?? 0),
            (float) ($this->penalty_pct ?? 0),
            (float) ($this->late_interest_rate_am ?? 0),
            $asOf,
            $this->settlementChargeRows(),
        );
    }

    public function balanceCents(?string $asOf = null): int
    {
        return $this->balanceAsOf($asOf)['balance'];
    }

    public function statusLabel(?string $asOf = null): string
    {
        $balance = $this->balanceAsOf($asOf);
        if ($balance['balance'] === 0) {
            return $this->installments->contains(fn ($part) => $part->settlements->contains('kind', 'loss')) ? 'Encerrado com perda' : 'Quitado';
        }
        if ($this->installments->contains(fn ($part) => $part->remainingCents() > 0 && $part->due_date?->lt(\Illuminate\Support\Carbon::parse($asOf ?? \App\Support\BusinessDate::today())))) {
            return 'Vencido';
        }

        return $balance['principal_open'] < $this->amountCents() || $balance['interest_open'] || $balance['penalty_open']
            ? ($balance['principal_open'] < $this->amountCents() ? 'Parcialmente recebido' : 'Em aberto')
            : 'Em aberto';
    }
}
