<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Whilesmart\Shareables\Contracts\ShareAudience;
use Whilesmart\Shareables\Models\Share;
use Whilesmart\Shareables\Traits\ReceivesShares;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Whilesmart\Holdings\Models\Holding;
use Whilesmart\AgentActions\Traits\HasAgentActions;
use Whilesmart\AgentMetrics\Traits\HasTokenUsage;
use Whilesmart\ModelConfiguration\Traits\Configurable;
use Whilesmart\Outreach\Traits\SendsOutreach;
use Whilesmart\Roles\Traits\HasRoles;
use Whilesmart\UserDevices\Traits\HasDevices;

class User extends Authenticatable implements HasLocalePreference, ShareAudience
{
    use Configurable;
    use HasAgentActions;
    use HasApiTokens;
    use HasDevices;
    use HasFactory;
    use HasRoles;
    use \Whilesmart\Workspaces\Traits\HasWorkspaces;
    use HasTokenUsage;
    use Notifiable;
    use SendsOutreach;
    use ReceivesShares;

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
        'tokens_used' => 'integer',
    ];

    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            foreach ([Transaction::class, Wallet::class] as $resource) {
                $resource::withTrashed()->ownedBy($this)->select('id')->lockForUpdate()->get();
            }

            return parent::delete();
        });
    }

    public function admits(?AuthenticatableContract $viewer): bool
    {
        return $viewer instanceof self && $this->is($viewer);
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            Share::whereHasMorph(
                'shareable',
                [Wallet::class, Transaction::class],
                fn ($resources) => $resources->withTrashed()->ownedBy($user)
            )->withTrashed()->forceDelete();
            $tables = ['transactions', 'transfers', 'wallets', 'categories', 'groups', 'parties',
                'reminders', 'file_imports', 'import_sessions'];
            foreach ($tables as $table) {
                \Illuminate\Support\Facades\DB::table($table)
                    ->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey())->delete();
            }
        });
    }

    public function categories(): MorphMany
    {
        return $this->morphMany(Category::class, 'owner');
    }

    public function groups(): MorphMany
    {
        return $this->morphMany(Group::class, 'owner');
    }

    public function parties(): MorphMany
    {
        return $this->morphMany(Party::class, 'owner');
    }

    public function wallets(): MorphMany
    {
        return $this->morphMany(Wallet::class, 'owner');
    }

    public function holdings(): MorphMany
    {
        return $this->morphMany(Holding::class, 'owner');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'owner');
    }

    public function transfers(): MorphMany
    {
        return $this->morphMany(Transfer::class, 'owner');
    }

    public function fileImports(): MorphMany
    {
        return $this->morphMany(FileImport::class, 'owner');
    }

    public function importSessions(): MorphMany
    {
        return $this->morphMany(ImportSession::class, 'owner');
    }

    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'owner');
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
