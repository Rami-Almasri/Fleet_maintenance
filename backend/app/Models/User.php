<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, hasApiTokens,HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        // Workforce activity snapshot (kept current by UserActivityService).
        'last_login_at',
        'last_logout_at',
        'last_seen_at',
        'last_page',
        'last_path',
        'last_ip',
        'last_user_agent',
        'failed_login_count',
        'last_failed_login_at',
        'last_password_change_at',
        'last_role_change_at',
        'created_by',
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'last_logout_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_failed_login_at' => 'datetime',
            'last_password_change_at' => 'datetime',
            'last_role_change_at' => 'datetime',
        ];
    }

    /** Append-only workforce activity log for this account. */
    public function activityEvents(): HasMany
    {
        return $this->hasMany(UserActivityEvent::class);
    }

    /** The admin who minted this account (null for bootstrap/legacy accounts). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
