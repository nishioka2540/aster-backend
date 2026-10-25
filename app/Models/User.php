<?php

namespace App\Models;

use Enum\AuthType;
use Enum\CastRole;
use Enum\UserType;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasUlids, Notifiable, SoftDeletes;

    /** @deprecated UserType::Staffを使用する。 */
    public const TYPE_STAFF = UserType::Staff->value;

    /** @deprecated UserType::Castを使用する。 */
    public const TYPE_CAST = UserType::Cast->value;

    public function isSystemAdministrator(): bool
    {
        return $this->is_system_admin;
    }

    public function isAdministrator(): bool
    {
        return $this->isSystemAdministrator();
    }

    public function canManageShifts(): bool
    {
        return app(\App\Services\StoreAccess::class)->allows($this, 'shift.manage');
    }

    public function canRegisterShifts(): bool
    {
        return app(\App\Services\StoreAccess::class)->allows($this, 'shift.submit');
    }

    public function canViewConfirmedShifts(): bool
    {
        return app(\App\Services\StoreAccess::class)->allows($this, 'shift_calendar.view');
    }

    public function homePath(): string
    {
        if ($this->canManageShifts()) {
            return '/request_shift';
        }
        if (! $this->canRegisterShifts() && $this->canViewConfirmedShifts()) {
            return '/confirmed_shifts';
        }

        return $this->canRegisterShifts()
            ? app(\App\Application\Shift\CastHomePath::class)->forUser((int) $this->getAuthIdentifier())
            : '/mypage';
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected $table = 'mst_users';

    protected $attributes = ['is_system_admin' => false, 'auth_type' => AuthType::Standard->value, 'user_type' => UserType::Cast->value, 'role_type' => CastRole::Other->value];

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
            'is_system_admin' => 'boolean',
            'auth_type' => 'integer',
            'user_type' => 'integer',
            'role_type' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
