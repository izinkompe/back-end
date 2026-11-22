<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, Password};
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function register(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:150', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()], 'role' => 'required|in:farmer,buyer', 'phone' => 'nullable|string|max:30', 'organization' => 'nullable|string|max:255']);
        $user = User::create($v);
        $user->sendEmailVerificationNotification();
        return response()->json($this->session($user), 201);
    }
    public function login(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', $v['email'])->first();
        if (!$user || !Hash::check($v['password'], $user->password)) { throw ValidationException::withMessages(['email' => 'Email atau kata sandi salah.']); }
        return $this->session($user);
    }
    private function session(User $user): array { return ['user' => $user, 'token' => $user->createToken('nextjs', ['*'], now()->addDays(7))->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => 604800]; }
    public function logout(Request $r) { $r->user()->currentAccessToken()?->delete(); return response()->noContent(); }
    public function forgot(Request $r)
    {
        $v = $r->validate(['email' => 'required|email']);
        if (User::where('email', $v['email'])->whereNotNull('email_verified_at')->exists()) { Password::sendResetLink($v); }
        return ['message' => 'Jika akun terdaftar, tautan reset telah dikirim.'];
    }
    public function reset(Request $r)
    {
        $v = $r->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()]]);
        $status = Password::reset($v, function (User $user, string $password) { $user->forceFill(['password' => $password, 'remember_token' => \Illuminate\Support\Str::random(60)])->save(); $user->tokens()->delete(); });
        if ($status !== Password::PASSWORD_RESET) { throw ValidationException::withMessages(['token' => 'Token reset tidak valid atau kedaluwarsa.']); }
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
