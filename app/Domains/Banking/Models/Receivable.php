<?php

namespace App\Domains\Banking\Models;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Writs\Models\Writ;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Receivable extends Model
{
    use LogsActivity;

    protected $fillable = ['contact_id', 'writ_id', 'description', 'date', 'repayment_mode', 'process_reference', 'notes', 'request_key'];

    protected $casts = ['date' => 'date'];

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

    public function remainingCents(): int
    {
        return $this->installments->sum(fn ($part) => $part->remainingCents());
    }

    public function statusLabel(): string
    {
        if ($this->remainingCents() === 0) {
            return $this->installments->contains(fn ($part) => $part->settlements->contains('kind', 'loss')) ? 'Encerrado com perda' : 'Quitado';
        }
        if ($this->installments->contains(fn ($part) => $part->remainingCents() > 0 && $part->due_date?->lt(today()))) {
            return 'Vencido';
        }

        return $this->remainingCents() < $this->amountCents() ? 'Parcialmente recebido' : 'Em aberto';
    }
}
