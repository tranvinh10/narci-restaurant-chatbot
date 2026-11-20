<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'city',
        'address',
        'phone',
        'opening_time',
        'closing_time',
        'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
