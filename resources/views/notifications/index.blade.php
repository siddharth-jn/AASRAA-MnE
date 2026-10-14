<x-layouts.app heading="Notifications">
    <div class="mb-5 flex justify-end"><form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn-secondary">Mark all as read</button></form></div>
    <div class="space-y-3">
        @forelse($notifications as $notification)
            <form method="POST" action="{{ route('notifications.read', $notification) }}">
                @csrf
                <button class="card block w-full p-5 text-left hover:border-brand-200 {{ $notification->read_at ? 'opacity-70' : '' }}">
                    <div class="flex items-start justify-between gap-4"><div><p class="font-semibold text-ink">{{ $notification->data['title'] ?? 'Notification' }}</p><p class="mt-1 text-sm text-slate-600">{{ $notification->data['message'] ?? '' }}</p><p class="mt-2 text-xs text-slate-400">{{ $notification->created_at->diffForHumans() }}</p></div>@unless($notification->read_at)<span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-brand-500"></span>@endunless</div>
                </button>
            </form>
        @empty<div class="card p-12 text-center"><p class="font-semibold text-ink">No notifications</p><p class="mt-2 text-sm text-slate-500">Workflow updates and deadline reminders will appear here.</p></div>@endforelse
    </div>
    <div class="mt-6">{{ $notifications->links() }}</div>
</x-layouts.app>
