<?php

namespace App\Domains\Writs\Models;

use App\Domains\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WritAssignor extends Model
{
    protected $fillable = ['writ_id', 'contact_id', 'role'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function writ(): BelongsTo
    {
        return $this->belongsTo(Writ::class);
    }
}
