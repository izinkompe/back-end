<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CookieAuthTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'pangantani_token';
    private const FRONTEND = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        User::create(['name' => 'Pembeli', 'email' => 'cookie@pangantani.test', 'role' => 'buyer', 'password' => 'Password123']);
    }

    private function login(): string
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'cookie@pangantani.test', 'password' => 'Password123'])->assertOk();
        $cookie = $response->getCookie(self::COOKIE, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame($response->json('token'), $cookie->getValue());
        $this->app['auth']->forgetGuards();

        return $cookie->getValue();
    }

    public function test_login_and_register_send_an_http_only_auth_cookie(): void
    {
        $this->login();
        $this->postJson('/api/v1/auth/register', ['name' => 'Baru', 'email' => 'baru@pangantani.test', 'password' => 'Password123', 'password_confirmation' => 'Password123', 'role' => 'farmer'])
            ->assertCreated()
            ->assertJsonPath('user.role', 'farmer')
            ->assertCookie(self::COOKIE, null, false);
    }

    public function test_registration_defaults_to_buyer_and_rejects_invalid_roles(): void
    {
        $payload = ['name' => 'Baru', 'email' => 'baru@pangantani.test', 'password' => 'Password123', 'password_confirmation' => 'Password123'];
        $this->postJson('/api/v1/auth/register', $payload)->assertCreated()->assertJsonPath('user.role', 'buyer');
        $this->assertDatabaseHas('users', ['email' => $payload['email'], 'role' => 'buyer']);

        $payload['email'] = 'lain@pangantani.test';
        foreach (['admin', '', null] as $role) {
            $this->postJson('/api/v1/auth/register', $payload + ['role' => $role])->assertUnprocessable()->assertJsonValidationErrors('role');
        }
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_cookie_alone_authenticates_protected_endpoints(): void
    {
        $token = $this->login();
        $this->withCredentials()
            ->withUnencryptedCookie(self::COOKIE, $token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'cookie@pangantani.test');
    }

    public function test_requests_without_cookie_or_bearer_are_rejected(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_and_registration_reject_untrusted_origins_without_a_cookie(): void
    {
        $this->withHeader('Origin', 'https://situs-jahat.test')
            ->postJson('/api/v1/auth/login', ['email' => 'cookie@pangantani.test', 'password' => 'Password123'])
            ->assertForbidden();
        $this->withHeader('Origin', 'https://situs-jahat.test')
            ->postJson('/api/v1/auth/register', ['name' => 'Baru', 'email' => 'baru@pangantani.test', 'password' => 'Password123', 'password_confirmation' => 'Password123', 'role' => 'buyer'])
            ->assertForbidden();
        $this->assertSame(0, User::first()->tokens()->count());
        $this->assertDatabaseMissing('users', ['email' => 'baru@pangantani.test']);
    }

    public function test_login_accepts_trusted_origin_and_rejects_cross_site_fetch_without_origin(): void
    {
        $credentials = ['email' => 'cookie@pangantani.test', 'password' => 'Password123'];
        $this->withHeader('Origin', self::FRONTEND)->postJson('/api/v1/auth/login', $credentials)->assertOk();
        $this->flushHeaders();
        $this->withHeader('Sec-Fetch-Site', 'cross-site')->postJson('/api/v1/auth/login', $credentials)->assertForbidden();
        $this->assertSame(1, User::first()->tokens()->count());
    }

    public function test_login_rate_limit_applies_to_the_same_account_from_different_ips(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i])
                ->postJson('/api/v1/auth/login', ['email' => 'cookie@pangantani.test', 'password' => 'wrong'])
                ->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.11'])
            ->postJson('/api/v1/auth/login', ['email' => 'COOKIE@PANGANTANI.TEST', 'password' => 'wrong'])
            ->assertTooManyRequests();
    }

    public function test_state_changes_with_cookie_reject_untrusted_browser_origins(): void
    {
        $token = $this->login();
        $this->withCredentials()
            ->withUnencryptedCookie(self::COOKIE, $token)
            ->withHeader('Origin', 'https://situs-jahat.test')
            ->postJson('/api/v1/auth/logout')
            ->assertForbidden();
        $this->withCredentials()
            ->withUnencryptedCookie(self::COOKIE, $token)
            ->withHeader('Sec-Fetch-Site', 'cross-site')
            ->postJson('/api/v1/auth/logout')
            ->assertForbidden();
        $this->assertSame(1, User::first()->tokens()->count());
    }

    public function test_logout_revokes_token_and_expires_cookie(): void
    {
        $token = $this->login();
        $this->withCredentials()
            ->withUnencryptedCookie(self::COOKIE, $token)
            ->withHeader('Origin', self::FRONTEND)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent()
            ->assertCookieExpired(self::COOKIE);
        $this->assertSame(0, User::first()->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withCredentials()
            ->withUnencryptedCookie(self::COOKIE, $token)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_explicit_bearer_header_takes_precedence_over_cookie(): void
    {
        $token = $this->login();
        $this->withCredentials()
            ->withUnencryptedCookie(self::COOKIE, 'token-cookie-tidak-valid')
            ->withToken($token)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_cors_allows_credentials_for_the_frontend_origin(): void
    {
        $this->withHeaders(['Origin' => self::FRONTEND, 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/v1/me')
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
