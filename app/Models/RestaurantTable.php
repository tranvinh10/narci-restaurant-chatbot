<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RestaurantTable extends Model
{
    protected $fillable = ['branch_id', 'table_code', 'capacity', 'seating_type', 'is_active'];

    protected $casts = ['capacity' => 'integer', 'is_active' => 'boolean'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_restaurant_table');
    }
}
