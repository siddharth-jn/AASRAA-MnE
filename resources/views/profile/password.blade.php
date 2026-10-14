<x-layouts.app title="Change password · AASRA IMPACT" heading="Change password">
<div class="mx-auto max-w-2xl"><div class="card p-6 md:p-8"><h2 class="text-lg font-bold text-ink">Secure your account</h2><p class="mt-1 text-sm text-slate-500">Changing your password closes your other active sessions.</p>
<form method="POST" action="{{ route('password.change') }}" class="mt-6 space-y-5">@csrf @method('PATCH')
<div><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" class="mt-1 w-full" required></div>
<div><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" class="mt-1 w-full" required></div>
<div><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="mt-1 w-full" required></div>
<button class="btn-primary">Change password</button>
</form></div></div>
</x-layouts.app>
