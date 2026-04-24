<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpLookup extends Model
{
    protected $fillable = ['ip', 'data', 'looked_up_at'];

    protected $casts = [
        'data'         => 'array',
        'looked_up_at' => 'datetime',
    ];
}
