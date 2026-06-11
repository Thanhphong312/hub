<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reason extends Model
{
    protected $fillable = ['name', 'code', 'qr_code'];

    public function reprints()
    {
        return $this->hasMany(Reprint::class);
    }

    /**
     * Unique "RSN" + 4 chars from an unambiguous alphabet (no 0/O/1/I). This is
     * also what the reason's QR encodes, so the scan page can tell a reason
     * (starts with RSN) from an order system_id.
     */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'RSN';
            for ($i = 0; $i < 4; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
