<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = ['intHoldID', 'decTotalPrice', 'strStatus', 'strTransactionID'];

    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class, 'intHoldID');
    }
}
