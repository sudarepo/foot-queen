<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CamClickEvent extends Model
{
    protected $guarded = [];

    public function cam(): BelongsTo
    {
        return $this->belongsTo(Cam::class);
    }
}
