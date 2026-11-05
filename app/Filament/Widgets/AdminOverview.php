<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Centres\CentreResource;
use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Centre;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkflowStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $roleCounts = User::query()
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');
        $activeUsers = User::query()->where('active', true)->count();
        $totalUsers = User::query()->count();

        return [
            Stat::make('Users', $totalUsers)
                ->description("{$activeUsers} active · ".($totalUsers - $activeUsers).' inactive')
                ->descriptionIcon('heroicon-m-users')
                ->url(UserResource::getUrl()),
            Stat::make('Administrators', $roleCounts->get('ADMIN', 0))
                ->description('Full configuration access')
                ->descriptionIcon('heroicon-m-shield-check')
                ->url(UserResource::getUrl()),
            Stat::make('Senior programme leads', $roleCounts->get('SENIOR PROGRAM LEAD', 0))
                ->description('Review and approval access')
                ->descriptionIcon('heroicon-m-check-badge')
                ->url(UserResource::getUrl()),
            Stat::make('Programme leads', $roleCounts->get('PROGRAM LEAD', 0))
                ->description('Reporting and import access')
                ->descriptionIcon('heroicon-m-user-group')
                ->url(UserResource::getUrl()),
            Stat::make('Project coordinators', $roleCounts->get('CENTRE COORDINATOR', 0))
                ->description('Project reporting access')
                ->descriptionIcon('heroicon-m-map-pin')
                ->url(UserResource::getUrl()),
            Stat::make('Viewers', $roleCounts->get('VIEWER', 0))
                ->description('Read-only reporting access')
                ->descriptionIcon('heroicon-m-eye')
                ->url(UserResource::getUrl()),
            Stat::make('Active programmes', Project::query()->where('active', true)->count())
                ->description('Manage programme settings and project assignments')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->url(ProjectResource::getUrl()),
            Stat::make('Active projects', Centre::query()->where('active', true)->count())
                ->description('Projects assigned to programmes')
                ->descriptionIcon('heroicon-m-building-office-2')
                ->url(CentreResource::getUrl()),
            Stat::make('Active indicators', IndicatorDefinition::query()->where('active', true)->count())
                ->description('Definitions currently in use')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->url(IndicatorDefinitionResource::getUrl()),
            Stat::make('Pending approvals', IndicatorEntry::query()->targetBacked()->whereIn('status', WorkflowStatus::pending())->count())
                ->description('Submitted records awaiting review')
                ->descriptionIcon('heroicon-m-clock'),
        ];
    }
}
