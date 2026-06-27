<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Whilesmart\ModelConfiguration\Traits\Configurable;
use Whilesmart\Roles\Traits\HasRoles;
use Whilesmart\UserDevices\Traits\HasDevices;

class User extends Authenticatable implements HasLocalePreference
{
    use Configurable;
    use HasApiTokens;
    use HasDevices;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'phone',
        'email',
        'password',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'avatar_url',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function fileImports(): HasMany
    {
        return $this->hasMany(FileImport::class);
    }

    public function importSessions(): HasMany
    {
        return $this->hasMany(ImportSession::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function budgets(): MorphMany
    {
        return $this->morphMany(Budget::class, 'owner');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->getConfigValue('avatar');
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->hasRole('admin');
    }

    public function preferredLocale(): ?string
    {
        $locale = $this->getConfigValue('default-lang');

        return is_string($locale) && $locale !== '' ? $locale : null;
    }
}
