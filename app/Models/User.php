<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'active', 'is_demo'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'active' => 'boolean', 'is_demo' => 'boolean'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active && $this->role === 'ADMIN';
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return asset('images/ame-mark.svg');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_user_assignments');
    }

    public function centres(): BelongsToMany
    {
        return $this->belongsToMany(Centre::class, 'centre_user_assignments');
    }
}
