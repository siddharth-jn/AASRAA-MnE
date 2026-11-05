<?php

namespace App\Providers;

use App\Database\ReadOnlySourceConnectionGuard;
use App\Models\EvidenceFile;
use App\Models\IndicatorEntry;
use App\Models\User;
use App\Services\ScopeService;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(ReadOnlySourceConnectionGuard $readOnlySourceConnectionGuard): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($readOnlySourceConnectionGuard): void {
            $readOnlySourceConnectionGuard->protect($event->connection);
        });

        Gate::define('configure', fn (User $u) => $u->active && $u->role === 'ADMIN');
        Gate::define('manage-targets', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'ASSISTANT PROGRAM LEAD', 'PROGRAM LEAD', 'SENIOR PROGRAM LEAD', 'M&E HEAD'], true));
        Gate::define('submit', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'ASSISTANT PROGRAM LEAD', 'PROGRAM LEAD', 'SENIOR PROGRAM LEAD', 'M&E HEAD', 'CENTRE COORDINATOR'], true));
        Gate::define('review', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'SENIOR PROGRAM LEAD', 'M&E HEAD'], true));
        Gate::define('bulk-approve', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'SENIOR PROGRAM LEAD', 'M&E HEAD'], true));
        Gate::define('bulk-approve-excel', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'SENIOR PROGRAM LEAD', 'M&E HEAD'], true));
        Gate::define('executive-dashboard', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'M&E HEAD', 'CEO', 'CFO'], true));
        Gate::define('quality', fn (User $u) => $u->active && in_array($u->role, ['ADMIN', 'SENIOR PROGRAM LEAD', 'PROGRAM LEAD', 'M&E HEAD'], true));
        Gate::define('import', fn (User $u) => false);
        Gate::define('view-entry', fn (User $u, IndicatorEntry $e) => app(ScopeService::class)->canAccess($u, $e));
        Gate::define('view-evidence', function (User $user, EvidenceFile $evidence): bool {
            if ($user->role === 'VIEWER' || ! app(ScopeService::class)->canAccess($user, $evidence->revision->entry)) {
                return false;
            }

            return ! in_array($user->role, ['CEO', 'CFO'], true)
                || $evidence->entry_revision_id === $evidence->revision->entry->approved_revision_id;
        });
        View::composer('components.layouts.app', function ($view): void {
            if (auth()->check()) {
                $view->with('navProjects', app(ScopeService::class)->projects(auth()->user())->orderBy('id')->get());
                $view->with('demoMode', User::where('is_demo', true)->exists());
                $view->with('unreadNotificationCount', auth()->user()->unreadNotifications()->count());
            }
        });
    }
}
