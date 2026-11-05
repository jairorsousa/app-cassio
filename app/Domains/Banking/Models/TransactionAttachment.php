<?php

namespace App\Domains\Banking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Comprovante anexado a um lançamento. Arquivo no disco privado "local"; download só autenticado. */
class TransactionAttachment extends Model
{
    public const DISK = 'local';

    public const MIMES = 'pdf,jpg,jpeg,png';

    public const MAX_KB = 10240;

    protected $fillable = ['transaction_id', 'path', 'original_name', 'mime', 'size', 'user_id'];

    protected $casts = ['size' => 'integer'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }
}
