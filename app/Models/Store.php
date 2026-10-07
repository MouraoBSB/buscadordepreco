<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'active',
        'trust_level',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}
