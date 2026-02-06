<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\StreakType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Whilesmart\ModelConfiguration\Traits\Configurable;
use Whilesmart\UserDevices\Traits\HasDevices;

class User extends Authenticatable
{
    use Configurable, HasApiTokens, HasDevices, HasFactory, Notifiable;

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
        'streaks',
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

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->getConfigValue('avatar');
    }

    public function getStreak(string $type): ?Streak
    {
        return $this->streaks()->where('type', $type)->first();
    }

    public function streaks(): HasMany
    {
        return $this->hasMany(Streak::class);
    }

    public function getStreaksAttribute(): Collection
    {
        return $this->streaks()->get();
    }

    public function updateStreak(StreakType $streakType): void
    {
        $streak = $this->streaks()->where('type', $streakType->value)->first();
        if (! $streak) {
            $streak = $this->streaks()->create([
                'type' => $streakType->value,
                'last_activity_date' => now(),
                'current_streak' => 1,
            ]);
        }

        $last_activity = $streak->last_activity_date;
        $diff = $last_activity->diffInDays(now(), true);
        if ($diff > 1) {
            if ($last_activity->diffInDays(now(), true) >= 2) {
                $streak->longest_streak = max($streak->longest_streak, $streak->current_streak);
                $streak->current_streak = 1;
            } else {
                $streak->current_streak = $streak->current_streak + 1;
            }

            $streak->last_activity_date = now();
            $streak->save();
        }
    }
}
