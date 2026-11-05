<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'AASRAA MnE' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="{ navOpen: false }">
    <div class="min-h-screen lg:flex">
        <div x-cloak x-show="navOpen" @click="navOpen=false" class="fixed inset-0 z-30 bg-slate-950/30 lg:hidden"></div>

        <aside :class="navOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white transition lg:translate-x-0">
            <a href="{{ route('dashboard') }}" aria-label="Go to main dashboard" class="border-b border-slate-200 px-5 py-4 hover:bg-slate-50">
                <img src="{{ asset('images/mne-brand.svg') }}" alt="AASRAA MnE — Monitoring and Evaluation" class="h-12 w-auto max-w-full">
            </a>

            <nav class="flex-1 space-y-1 overflow-y-auto p-3 text-sm">
                @foreach ([['dashboard', 'Dashboard', '⌂'], ['programmes.index', 'Programmes', '▦'], ['projects.index', 'Projects', '⌖']] as [$routeName, $label, $icon])
                    <a href="{{ route($routeName) }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium {{ request()->routeIs(str_replace('.index', '.*', $routeName)) || request()->routeIs($routeName) ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"><span class="w-5 text-center">{{ $icon }}</span>{{ $label }}</a>
                @endforeach
                @can('submit')
                    <a href="{{ route('entries.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium {{ request()->routeIs('entries.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"><span class="w-5 text-center">✎</span>Data Entry</a>
                @endcan
                @can('manage-targets')
                    <a href="{{ route('targets.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium {{ request()->routeIs('targets.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"><span class="w-5 text-center">◎</span>Targets</a>
                @endcan
                @can('review')
                    <a href="{{ route('approvals.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium {{ request()->routeIs('approvals.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"><span class="w-5 text-center">✓</span>Approvals</a>
                @endcan
                <a href="{{ route('reports.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium {{ request()->routeIs('reports.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"><span class="w-5 text-center">▤</span>Reports</a>
                @can('executive-dashboard')
                    <a href="{{ route('executive.dashboard') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium {{ request()->routeIs('executive.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"><span class="w-5 text-center">◫</span>Executive overview</a>
                @endcan
                @can('configure')
                    <a href="{{ route('documents.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium {{ request()->routeIs('documents.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"><span class="w-5 text-center">▣</span>Documents</a>
                    @if(config('impact.authoritative_project_sync_enabled'))
                        <a href="{{ route('administration.project-sync.index') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium {{ request()->routeIs('administration.project-sync.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"><span class="w-5 text-center">↻</span>Project sync</a>
                    @endif
                    <a href="{{ url('/admin') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 font-medium text-slate-600 hover:bg-slate-50 hover:text-slate-900"><span class="w-5 text-center">⚙</span>Administration</a>
                @endcan
            </nav>

            <div class="border-t border-slate-200 px-4 py-3">
                <p class="truncate text-sm font-semibold text-slate-800">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-slate-500">{{ str_replace(['Program', 'Centre'], ['Programme', 'Project'], Str::headline(strtolower(auth()->user()->role))) }}</p>
            </div>
        </aside>

        @php($headerDate = now())
        @php($financialYearStart = $headerDate->month < config('impact.financial_year_start_month', 4) ? $headerDate->year - 1 : $headerDate->year)
        <div class="min-w-0 flex-1 lg:pl-64">
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white/95 px-4 shadow-sm backdrop-blur md:px-8">
                <div class="flex items-center gap-3">
                    <button @click="navOpen=true" class="rounded-lg p-2 hover:bg-slate-100 lg:hidden" aria-label="Open navigation">☰</button>
                    <div>
                        <h1 class="text-lg font-bold text-ink">{{ $heading ?? 'Dashboard' }}</h1>
                        <p class="hidden text-xs text-slate-500 sm:block">FY {{ $financialYearStart }}–{{ str_pad(($financialYearStart + 1) % 100, 2, '0', STR_PAD_LEFT) }} · {{ $headerDate->format('d M Y') }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    @if($demoMode ?? false)
                        <span class="badge bg-amber-100 text-amber-800">DEMO MODE</span>
                    @endif
                    <a href="{{ route('notifications.index') }}" aria-label="Notifications" title="Notifications" class="relative grid h-9 w-9 place-items-center rounded-full border border-slate-200 text-slate-600 hover:bg-slate-50">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9a6 6 0 0 0-12 0v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.08 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                        @if(($unreadNotificationCount ?? 0) > 0)
                            <span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-500 px-1 text-center text-[10px] font-bold leading-5 text-white">{{ min(99, $unreadNotificationCount) }}</span>
                        @endif
                    </a>

                    <details class="group relative">
                        <summary aria-label="Open account menu" class="grid h-10 w-10 cursor-pointer list-none place-items-center overflow-hidden rounded-full ring-2 ring-transparent transition hover:ring-brand-200 focus:outline-none focus:ring-brand-300 [&::-webkit-details-marker]:hidden">
                            <img src="{{ asset('images/mne-mark.svg') }}" alt="MnE" class="h-full w-full">
                        </summary>
                        <div role="menu" class="absolute right-0 top-full z-50 w-64 pt-2">
                            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                                <div class="border-b border-slate-100 px-4 py-3">
                                    <p class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                                    <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
                                </div>
                                <div class="p-2 text-sm">
                                    <a href="{{ route('profile.show') }}" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50">My profile</a>
                                    @can('configure')
                                        <a href="{{ url('/admin') }}" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50">Administration</a>
                                    @endcan
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button role="menuitem" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-red-700 hover:bg-red-50">Sign out</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>
            </header>

            <main class="p-4 md:p-8">
                @if(session('success'))
                    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
                @endif
                @if($errors->any())
                    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
