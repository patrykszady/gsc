<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'site_id',
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
        ];
    }

    /* ---------------------------------------------------------------------
     | Admin scoping
     |
     | site_id NULL  = platform admin: every tenant.
     | site_id set   = client login: that tenant only, enforced in
     |                 ResolveAdminSite (403) and in the /admin hub, which
     |                 sends a single-site user straight to their own admin.
     --------------------------------------------------------------------- */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Platform admins are unscoped — they administer every tenant. */
    public function isPlatformAdmin(): bool
    {
        return $this->site_id === null;
    }

    public function canAccessSite(Site $site): bool
    {
        return $this->isPlatformAdmin() || $this->site_id === $site->id;
    }

    /**
     * Every site this user may administer, in the picker's order.
     *
     * @return Collection<int, Site>
     */
    public function accessibleSites(): Collection
    {
        $sites = Site::listAll();

        return $this->isPlatformAdmin()
            ? $sites
            : $sites->where('id', $this->site_id)->values();
    }
}
