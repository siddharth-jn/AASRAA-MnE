<?php

namespace App\Providers;

use App\Models\EvidenceFile;
use App\Models\IndicatorEntry;
use App\Models\User;
use App\Services\ScopeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::define('configure', fn (User $u) => $u->active && $u->role === 'ADMIN');
        Gate::define('submit', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'PROGRAM LEAD', 'CENTRE COORDINATOR', 'SENIOR PROGRAM LEAD'], true));
        Gate::define('review', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'SENIOR PROGRAM LEAD'], true));
        Gate::define('quality', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'SENIOR PROGRAM LEAD', 'PROGRAM LEAD'], true));
        Gate::define('import', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'SENIOR PROGRAM LEAD', 'PROGRAM LEAD'], true));
        Gate::define('view-entry', fn (User $u, IndicatorEntry $e) => app(ScopeService::class)->canAccess($u, $e));
        Gate::define('view-evidence', fn (User $u, EvidenceFile $f) => $u->role !== 'VIEWER' && app(ScopeService::class)->canAccess($u, $f->revision->entry));
        View::composer('components.layouts.app', function ($view): void {
            if (auth()->check()) {
                $view->with('navProjects', app(ScopeService::class)->projects(auth()->user())->orderBy('id')->get());
                $view->with('demoMode', User::where('is_demo', true)->exists());
                $view->with('unreadNotificationCount', auth()->user()->unreadNotifications()->count());
            }
        });
    }
}
