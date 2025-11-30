<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['strName', 'strSku', 'strDescription', 'decPrice', 'intStock'];

    protected $casts = [
        'decPrice' => 'decimal:2',
    ];

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }
}
