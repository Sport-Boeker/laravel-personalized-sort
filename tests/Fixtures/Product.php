<?php

namespace SportBoeker\PersonalizedSort\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SportBoeker\PersonalizedSort\Models\Concerns\HasPersonalizedRanking;

class Product extends Model
{
    use HasPersonalizedRanking;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['quality' => 'float', 'in_stock' => 'boolean'];
}
