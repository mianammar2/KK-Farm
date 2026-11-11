<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** D-211: a dairy product batch made from milk sent to processing; released by someone other than the maker. */
class MilkProcessingBatch extends Model
{
    protected $guarded = [];

    protected $casts = ['input_l' => 'float', 'output_qty' => 'float', 'heat_temp_c' => 'float', 'made_on' => 'date', 'best_before' => 'date', 'released_at' => 'datetime'];

    public const PRODUCTS = ['yogurt' => ['Yogurt (dahi)', 'kg'], 'cheese' => ['Cheese', 'kg'], 'ghee' => ['Ghee', 'kg'], 'khoa' => ['Khoa', 'kg'], 'pasteurised' => ['Pasteurised milk', 'L'], 'lassi' => ['Lassi', 'L'], 'other' => ['Other', 'kg']];

    /** Products made from heat-treated milk: release needs the heat treatment recorded. */
    public const HEATED = ['yogurt', 'cheese', 'pasteurised', 'lassi'];

    public function dispatch()
    {
        return $this->belongsTo(MilkDispatch::class, 'dispatch_id');
    }
}
