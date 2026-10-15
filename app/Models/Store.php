<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    // Níveis em que o preço da loja é usado como preço principal no painel e nos alertas
    public const array TrustedLevels = ['official', 'marketplace_1p'];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function scopeTrusted(Builder $query): Builder
    {
        return $query->whereIn('trust_level', self::TrustedLevels);
    }

    public function isTrusted(): bool
    {
        return in_array($this->trust_level, self::TrustedLevels, true);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}
