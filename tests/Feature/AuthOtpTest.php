<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthOtpTest extends TestCase
{
    use RefreshDatabase;

    private function captureCode(): object
    {
        $message = (object) ['code' => null];
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body) use ($message) {
            preg_match('/\b\d{6}\b/', $body, $matches);
            $message->code = $matches[0] ?? null;
        });
        return $message;
    }

    public function test_email_otp_is_hashed_and_can_only_be_used_once(): void
    {
        $user = User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'role' => 'buyer', 'password' => 'Password123']);
        $token = $user->createToken('test')->plainTextToken;
        $message = $this->captureCode();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/orders')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/auth/email/otp')->assertOk();
        $this->assertNotNull($message->code);
        $this->assertTrue(Hash::check($message->code, DB::table('auth_otps')->value('code')));
        $this->withToken($token)->postJson('/api/v1/auth/email/verify-otp', ['code' => $message->code])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/orders')->assertOk();
        $this->withToken($token)->postJson('/api/v1/auth/email/verify-otp', ['code' => $message->code])->assertUnprocessable();
    }

    public function test_reset_otp_revokes_sessions_and_old_reset_links(): void
    {
        $user = User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'role' => 'buyer', 'password' => 'Password123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $token = $user->createToken('test')->plainTextToken;
        $oldReset = Password::createToken($user);
        $message = $this->captureCode();
        $this->postJson('/api/v1/auth/forgot-password/otp', ['email' => $user->email])->assertOk();
        $data = ['email' => $user->email, 'code' => $message->code, 'password' => 'Changed123', 'password_confirmation' => 'Changed123'];
        $this->postJson('/api/v1/auth/reset-password/otp', $data)->assertOk();
        $this->assertTrue(Hash::check('Changed123', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/api/v1/auth/reset-password/otp', $data)->assertUnprocessable();
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $oldReset, 'password' => 'Changed123', 'password_confirmation' => 'Changed123'])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_wrong_codes_lock_the_otp_and_expired_codes_are_rejected(): void
    {
        $user = User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'role' => 'buyer', 'password' => 'Password123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $message = $this->captureCode();
        $this->postJson('/api/v1/auth/forgot-password/otp', ['email' => $user->email])->assertOk();
        $wrong = $message->code === '111111' ? '222222' : '111111';
        $data = ['email' => $user->email, 'code' => $wrong, 'password' => 'Changed123', 'password_confirmation' => 'Changed123'];
        for ($i = 0; $i < 5; $i++) { $this->postJson('/api/v1/auth/reset-password/otp', $data)->assertUnprocessable(); }
        $data['code'] = $message->code;
        $this->postJson('/api/v1/auth/reset-password/otp', $data)->assertUnprocessable();
        DB::table('auth_otps')->update(['attempts' => 0, 'expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/reset-password/otp', $data)->assertUnprocessable();
    }

    public function test_link_reset_invalidates_the_outstanding_reset_otp(): void
    {
        $user = User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'role' => 'buyer', 'password' => 'Password123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $linkToken = Password::createToken($user);
        $message = $this->captureCode();
        $this->postJson('/api/v1/auth/forgot-password/otp', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password/otp', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $linkToken, 'password' => 'Changed123', 'password_confirmation' => 'Changed123'])->assertOk();
        $this->postJson('/api/v1/auth/reset-password/otp', ['email' => $user->email, 'code' => $message->code, 'password' => 'Another123', 'password_confirmation' => 'Another123'])->assertUnprocessable();
    }

    public function test_email_verification_is_required_for_farmer_and_admin_routes(): void
    {
        foreach (['farmer' => '/api/v1/farmer/offers', 'admin' => '/api/v1/admin/verifications'] as $role => $path) {
            $user = User::create(['name' => $role, 'email' => $role.'@example.test', 'role' => $role, 'password' => 'Password123']);
            $token = $user->createToken('test')->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson($path)->assertForbidden();
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson($path)->assertOk();
        }
    }
}
