<?php

namespace App\Services;

use App\Models\User;
use App\Models\LoginHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function attempt(string $email, string $password): ?User
    {
        $user = User::query()->where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    public function recordLogin(User $user, Request $request): void
    {
        // L'authentification doit rester disponible sur une base qui n'a pas
        // encore reçu la migration de l'historique.
        if (!Schema::hasTable('login_histories')) {
            return;
        }

        LoginHistory::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'logged_in_at' => now(),
        ]);
    }
}
