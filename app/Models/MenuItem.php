<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $fillable = [
        'category',
        'name',
        'description',
        'price',
        'is_vegetarian',
        'is_spicy',
        'contains_seafood',
        'allergens',
        'allow_half_half',
        'image_url',
        'is_available',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_vegetarian' => 'boolean',
        'is_spicy' => 'boolean',
        'contains_seafood' => 'boolean',
        'allergens' => 'array',
        'allow_half_half' => 'boolean',
        'is_available' => 'boolean',
    ];

    public function scopeCategory($query, ?string $category)
    {
        return $category ? $query->where('category', $category) : $query;
    }

    public function scopeVegetarian($query)
    {
        return $query->where('is_vegetarian', true);
    }

    public function scopeNotSpicy($query)
    {
        return $query->where('is_spicy', false);
    }

    public function scopeNoSeafood($query)
    {
        return $query->where('contains_seafood', false);
    }
}
