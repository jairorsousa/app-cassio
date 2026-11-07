<?php

namespace App\Domains\Divisions\Models;

use Illuminate\Database\Eloquent\Model;

class DivisionCategory extends Model
{
    protected $fillable = ['key', 'label', 'created_by'];
}
