<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->load([
            'projects' => fn ($query) => $query->orderBy('name'),
            'centres' => fn ($query) => $query->orderBy('name'),
        ]);

        return view('profile.show', compact('user'));
    }
}
