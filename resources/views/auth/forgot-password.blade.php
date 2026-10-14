<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset password · AASRA IMPACT</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="min-h-screen bg-slate-100"><main class="flex min-h-screen items-center justify-center p-6"><form method="POST" action="{{ route('password.email') }}" class="card w-full max-w-md p-8 md:p-10">@csrf
<div class="mb-7"><div class="mb-5 grid h-12 w-12 place-items-center rounded-xl bg-brand-600 font-black text-white">AI</div><h1 class="text-2xl font-bold text-ink">Reset your password</h1><p class="mt-2 text-sm leading-6 text-slate-500">Enter your staff email address and we will send you a secure reset link.</p></div>
@if(session('status'))<p class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</p>@endif
@error('email')<p class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $message }}</p>@enderror
<label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" class="mt-1 w-full" required autofocus>
<button class="btn-primary mt-5 w-full">Email reset link</button><a href="{{ route('login') }}" class="mt-5 block text-center text-sm font-semibold text-brand-700">Back to sign in</a>
</form></main></body></html>
