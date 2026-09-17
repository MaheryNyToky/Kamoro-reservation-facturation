<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardAuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {
    }

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check() && in_array(Auth::user()?->role, ['admin', 'superadmin'], true)) {
            return redirect('/dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:255',
        ]);

        $user = $this->authService->attempt($validated['email'], $validated['password']);
        if (!$user || !in_array($user->role, ['admin', 'superadmin'], true)) {
            return back()
                ->withErrors(['email' => 'Accès réservé aux comptes administrateur et superadmin.'])
                ->onlyInput('email');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->authService->recordLogin($user, $request);

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/dashboard/login');
    }
}
