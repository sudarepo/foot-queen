<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChaturbateStatsDay extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'payout' => 'decimal:4',
        'is_ledger' => 'boolean',
        'data' => 'array',
    ];
}
