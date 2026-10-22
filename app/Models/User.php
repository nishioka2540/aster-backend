<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUlids, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->login_id = app(\App\Services\LoginIdGenerator::class)->next((int) $user->user_type);
        });
    }

    public const TYPE_STAFF = 1;
    public const TYPE_CAST = 2;

    public function canManageShifts(): bool
    {
        return $this->auth_type === 1
            || ($this->user_type === self::TYPE_STAFF && in_array($this->role_type, [1, 2], true));
    }

    public function canRegisterShifts(): bool
    {
        return $this->auth_type === 1 || $this->user_type === self::TYPE_CAST;
    }

    public function canViewConfirmedShifts(): bool
    {
        return $this->canManageShifts()
            || ($this->user_type === self::TYPE_STAFF && $this->role_type === 4)
            || ($this->user_type === self::TYPE_CAST && $this->role_type === 1);
    }

    public function homePath(): string
    {
        if ($this->canManageShifts()) {
            return '/request_shift';
        }
        if ($this->user_type === self::TYPE_STAFF && $this->canViewConfirmedShifts()) {
            return '/confirmed_shifts';
        }
        return $this->canRegisterShifts()
            ? app(\App\Application\Shift\CastHomePath::class)->forUser((int) $this->getAuthIdentifier())
            : '/access_denied';
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected $table = 'mst_users';

    protected $attributes = ['auth_type' => 0, 'user_type' => 2, 'role_type' => 3];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'line_id',
        'line_picture_url',
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
            'auth_type' => 'integer',
            'user_type' => 'integer',
            'role_type' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
