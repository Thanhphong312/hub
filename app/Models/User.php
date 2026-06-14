<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'tier_id',
        'wallet',
        'api_key',
        'status',
        'convert',
        'auto_pay',
        'auto_pay_delay_hours',
        'resend_free_quota',
        'resend_support_override',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet' => 'decimal:2',
            'convert' => 'boolean',
            'auto_pay' => 'boolean',
            'auto_pay_delay_hours' => 'integer',
            'resend_support_override' => 'array',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function tier()
    {
        return $this->belongsTo(Tier::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Gangsheets an admin assigned to this partner user (partner-bullstart app).
    public function assignedGangsheets()
    {
        return $this->belongsToMany(Gangsheet::class, 'gangsheet_partner', 'user_id', 'gangsheet_id')
            ->withPivot('printed_at')->withTimestamps();
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->role && $this->role->slug === $slug;
    }

    public function hasPermission(string $module, string $action = 'can_view'): bool
    {
        if (!$this->role) return false;
        $permission = $this->role->permissions()->where('module', $module)->first();
        return $permission && $permission->{$action};
    }
}
