<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Reservation extends Model
{
    protected $fillable = [
        'booking_code',
        'branch_id',
        'customer_name',
        'phone',
        'email',
        'reservation_date',
        'start_time',
        'end_time',
        'party_size',
        'seating_preference',
        'occasion',
        'notes',
        'status',
        'source',
    ];

    protected $casts = ['reservation_date' => 'date', 'party_size' => 'integer'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function tables(): BelongsToMany
    {
        return $this->belongsToMany(RestaurantTable::class, 'reservation_restaurant_table');
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'cancelled');
    }
}
