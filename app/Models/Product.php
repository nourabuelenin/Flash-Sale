<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['strName', 'strSku', 'strDescription', 'decPrice', 'intStock'];

    protected $casts = [
        'decPrice' => 'decimal:2',
    ];

    /**
     * Boot method to register model events for cache invalidation
     */
    protected static function boot()
    {
        parent::boot();

        // Invalidate cache whenever product is updated or deleted
        static::updated(function ($product) {
            Cache::forget("product:{$product->id}");
        });

        static::deleted(function ($product) {
            Cache::forget("product:{$product->id}");
        });
    }

    /**
     * Get the cache key for this product
     */
    public function getCacheKey(): string
    {
        return "product:{$this->id}";
    }

    /**
     * Manually invalidate this product's cache
     */
    public function invalidateCache(): void
    {
        Cache::forget($this->getCacheKey());
    }

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }
}
