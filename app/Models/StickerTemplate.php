<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StickerTemplate extends Model
{
    protected $fillable = ['name', 'note', 'sticker_count', 'phash', 'sample_url', 'created_by_user_id'];

    public function analyses()
    {
        return $this->hasMany(StickerSheetAnalysis::class, 'template_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
