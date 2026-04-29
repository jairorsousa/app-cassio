<?php

namespace App\Domains\Writs\Events;

use App\Domains\Writs\Models\Writ;
use Illuminate\Foundation\Events\Dispatchable;

class WritMovedToPaid
{
    use Dispatchable;

    public function __construct(public Writ $writ)
    {
    }
}
