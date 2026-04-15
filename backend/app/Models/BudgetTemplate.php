<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'amount',
        'note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
