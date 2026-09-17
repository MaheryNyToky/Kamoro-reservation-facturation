<?php

namespace Tests\Feature;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_login_records_receptionist_connection(): void
    {
        $user = User::create([
            'name' => 'Réception Test',
            'email' => 'reception.login@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'receptionist',
            'is_blacklisted' => false,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => 'receptionist',
        ]);
    }

    public function test_dashboard_displays_recent_login_history(): void
    {
        $user = User::create([
            'name' => 'Admin Historique',
            'email' => 'admin.history@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'admin',
            'is_blacklisted' => false,
        ]);

        LoginHistory::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'logged_in_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Historique des connexions')
            ->assertSee('Admin Historique');
    }
}
