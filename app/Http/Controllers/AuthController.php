<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Support\AuthCookie;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Hash, Password};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function register(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()], 'role' => 'sometimes|required|in:farmer,buyer', 'phone' => 'nullable|string|max:30', 'organization' => 'nullable|string|max:255']);
        $v['role'] ??= 'buyer';
        $user = User::create($v);
        $user->sendEmailVerificationNotification();
        return $this->issueSession($user, 201);
    }
    public function login(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        return DB::transaction(function () use ($v) {
            $user = User::where('email', $v['email'])->lockForUpdate()->first();
            if (!$user || !Hash::check($v['password'], $user->password)) { throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']); }
            return $this->issueSession($user);
        }, 3);
    }
    /** Token dikirim lewat cookie HttpOnly untuk browser dan di body JSON untuk klien non-browser. */
    private function issueSession(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('nextjs', ['*'], now()->addMinutes(AuthCookie::TTL_MINUTES))->plainTextToken;
        return response()
            ->json(['user' => $user, 'token' => $token, 'token_type' => 'Bearer', 'expires_in' => AuthCookie::TTL_MINUTES * 60], $status)
            ->withCookie(AuthCookie::make($token));
    }
    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()?->delete();
        return response()->noContent()->withCookie(AuthCookie::forget());
    }
    public function forgot(Request $r)
    {
        $v = $r->validate(['email' => 'required|email']);
        if (User::where('email', $v['email'])->whereNotNull('email_verified_at')->exists()) { Password::sendResetLink($v); }
        return ['message' => 'Jika akun terdaftar, tautan reset telah dikirim.'];
    }
    public function reset(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()]]);
        $user = DB::transaction(function () use ($v) {
            $row = DB::table('password_reset_tokens')->where('email', $v['email'])->lockForUpdate()->first();
            $expire = (int) config('auth.passwords.users.expire', 60);
            if (!$row || !$row->created_at || Carbon::parse($row->created_at)->addMinutes($expire)->isPast()) { return null; }
            if (!Hash::check($v['token'], $row->token)) { return null; }
            $user = User::where('email', $v['email'])->lockForUpdate()->first();
            if (!$user) { DB::table('password_reset_tokens')->where('email', $v['email'])->delete(); return null; }
            $user->forceFill(['password' => $v['password'], 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            DB::table('auth_otps')->where('user_id', $user->id)->where('purpose', 'reset')->delete();
            DB::table('password_reset_tokens')->where('email', $v['email'])->delete();
            return $user;
        }, 3);
        if (!$user) { throw ValidationException::withMessages(['token' => 'Token reset tidak valid atau kedaluwarsa.']); }
        event(new PasswordReset($user));
        return ['message' => 'Kata sandi telah diubah.'];
    }
    public function verificationNotice(Request $r)
    {
        if (!$r->user()->hasVerifiedEmail()) { $r->user()->sendEmailVerificationNotification(); }
        return ['message' => 'Jika email belum terverifikasi, tautan verifikasi telah dikirim.'];
    }
    public function verify(\Illuminate\Foundation\Auth\EmailVerificationRequest $r)
    {
        $r->fulfill(); return ['message' => 'Email berhasil diverifikasi.'];
    }
}
