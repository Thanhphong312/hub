<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MergedLabel extends Model
{
    const STATUS_NOT_SENT = 1;
    const STATUS_SENT     = 2;

    protected $fillable = ['label_url', 'status'];

    public function pushTrackings()
    {
        return $this->hasMany(PushTracking::class, 'merged_id');
    }
}
