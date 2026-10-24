<x-layouts.app title="My profile · AASRAA MnE" heading="My profile">
    <div class="mx-auto max-w-5xl space-y-6">
        <section class="card p-6 md:p-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <img src="{{ asset('images/mne-mark.svg') }}" alt="MnE" class="h-16 w-16 rounded-2xl">
                    <div>
                        <h2 class="text-xl font-bold text-ink">{{ $user->name }}</h2>
                        <p class="text-sm text-slate-500">{{ $user->email }}</p>
                        <span class="badge mt-2 bg-brand-50 text-brand-700">{{ str_replace(['Program', 'Centre'], ['Programme', 'Project'], Str::headline(strtolower($user->role))) }}</span>
                    </div>
                </div>
                <a href="{{ route('password.edit') }}" class="btn-secondary">Change password</a>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="card p-6">
                <h2 class="font-bold text-ink">Programme access</h2>
                @if($user->role === 'ADMIN')
                    <p class="mt-3 text-sm text-slate-600">You can access all programmes as an administrator.</p>
                @else
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse($user->projects as $project)
                            <span class="badge bg-slate-100 text-slate-700">{{ $project->name }}</span>
                        @empty
                            <p class="text-sm text-slate-500">No programmes have been assigned.</p>
                        @endforelse
                    </div>
                @endif
            </section>

            <section class="card p-6">
                <h2 class="font-bold text-ink">Project access</h2>
                @if($user->role === 'ADMIN')
                    <p class="mt-3 text-sm text-slate-600">You can access all projects as an administrator.</p>
                @else
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse($user->centres as $centre)
                            <span class="badge bg-slate-100 text-slate-700">{{ $centre->name }}</span>
                        @empty
                            <p class="text-sm text-slate-500">No projects have been assigned.</p>
                        @endforelse
                    </div>
                @endif
            </section>
        </div>

        @can('configure')
            <section class="card flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="font-bold text-ink">Administration</h2>
                    <p class="mt-1 text-sm text-slate-500">Manage users, access, programme monitoring setup, projects, indicators, and targets.</p>
                </div>
                <a href="{{ url('/admin') }}" class="btn-primary">Open administration</a>
            </section>
        @endcan
    </div>
</x-layouts.app>
