<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $fillable = ['intProductID', 'intQuantity', 'tmExpire', 'tmRelease', 'tmConvertedToOrder', 'strHoldToken']; 

    protected $casts = [
        'tmExpire' => 'datetime',
        'tmRelease' => 'datetime',
        'tmConvertedToOrder' => 'datetime',
    ];

    public function isValid(): bool
    {
        return is_null($this->tmRelease) && is_null($this->tmConvertedToOrder) && $this->tmExpire->isFuture();
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'intProductID');
    }
    
    public function order()
    {
        return $this->hasOne(Order::class, 'intHoldID');
    }
}
