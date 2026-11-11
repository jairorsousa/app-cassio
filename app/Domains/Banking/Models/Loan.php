<?php

namespace App\Domains\Banking\Models;

use App\Domains\Contacts\Models\Contact;
use App\Support\SimpleInterest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Loan extends Model
{
    use LogsActivity;
    use \App\Support\HidesTestRecords;

    protected $fillable = [
        'is_test', 'contact_id', 'bank_account_id', 'disbursement_transaction_id', 'description',
        'principal', 'date', 'installment_count', 'first_due_date', 'interest_rate_am', 'interest_type', 'notes',
        'attachment_path', 'attachment_original_name', 'attachment_mime', 'attachment_size', 'request_key',
    ];

    protected $casts = [
        'is_test' => 'boolean',
        'principal' => 'decimal:2',
        'date' => 'date',
        'first_due_date' => 'date',
        'interest_rate_am' => 'float',
        'installment_count' => 'integer',
        'attachment_size' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('loans');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class)->withTrashed();
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'disbursement_transaction_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('number');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(LoanSettlement::class)->orderBy('date')->orderBy('id');
    }

    public function principalCents(): int
    {
        return (int) round((float) $this->principal * 100);
    }

    /** @return list<array{date:string,principal:int,interest:int,penalty:int}> */
    public function settlementRows(): array
    {
        return $this->settlements->map(fn ($row) => [
            'date' => $row->date->toDateString(),
            'principal' => (int) round((float) $row->principal_amount * 100),
            'interest' => (int) round((float) $row->interest_amount * 100),
            'penalty' => 0,
        ])->all();
    }

    public function dueDateForCharges(): ?string
    {
        $open = $this->installments->first(fn ($part) => $part->remainingPrincipalCents() > 0);

        return ($open?->due_date ?? $this->first_due_date)?->toDateString();
    }

    public function interestType(): string
    {
        return SimpleInterest::normalizeType($this->interest_type ?? SimpleInterest::TYPE_SIMPLE);
    }

    public function balanceAsOf(?string $asOf = null): array
    {
        $asOf ??= \App\Support\BusinessDate::today();

        return SimpleInterest::balance(
            $this->principalCents(),
            $this->date->toDateString(),
            $this->dueDateForCharges(),
            (float) $this->interest_rate_am,
            0,
            0,
            $asOf,
            $this->settlementRows(),
            $this->interestType(),
        );
    }

    public function remainingCents(?string $asOf = null): int
    {
        return $this->balanceAsOf($asOf)['balance'];
    }

    public function principalOpenCents(?string $asOf = null): int
    {
        return $this->balanceAsOf($asOf)['principal_open'];
    }

    public function statusLabel(?string $asOf = null): string
    {
        $balance = $this->balanceAsOf($asOf);
        if ($balance['balance'] === 0) {
            return 'Quitado';
        }
        $due = $this->dueDateForCharges();
        if ($due && Carbon::parse($asOf ?? \App\Support\BusinessDate::today())->gt(Carbon::parse($due))) {
            return 'Vencido';
        }

        return $balance['principal_open'] < $this->principalCents() ? 'Parcialmente recebido' : 'Em aberto';
    }

    public function hasAttachment(): bool
    {
        return (bool) $this->attachment_path;
    }

    public function deleteAttachment(): void
    {
        if ($this->attachment_path) {
            Storage::disk('local')->delete($this->attachment_path);
        }
        $this->forceFill([
            'attachment_path' => null,
            'attachment_original_name' => null,
            'attachment_mime' => null,
            'attachment_size' => null,
        ])->save();
    }
}
