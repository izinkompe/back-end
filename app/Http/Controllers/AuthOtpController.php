<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuthOtp;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthOtpController
{
    public function __construct(private AuthOtp $otp) {}

    public function sendVerification(Request $request)
    {
        if (!$request->user()->hasVerifiedEmail()) {
            $this->otp->send($request->user(), 'verification');
        }
        return ['message' => 'Jika email belum terverifikasi, kode telah dikirim.'];
    }

    public function sendReset(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $user = User::where('email', $data['email'])->whereNotNull('email_verified_at')->first();
        if ($user) {
            try { $this->otp->send($user, 'reset'); }
            catch (\Throwable $exception) { report($exception); }
        }
        return ['message' => 'Jika akun memenuhi syarat, kode telah dikirim.'];
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|digits:6']);
        $user = $this->consume($request->user()->id, 'verification', $data['code']);
        if (!$user) { throw ValidationException::withMessages(['code' => 'Kode tidak valid atau kedaluwarsa.']); }
        event(new Verified($user));
        return ['message' => 'Email berhasil diverifikasi.'];
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'code' => 'required|string|digits:6', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()]]);
        $id = User::where('email', $data['email'])->value('id');
        $user = $id ? $this->consume($id, 'reset', $data['code'], $data['password'], $data['email']) : null;
        if (!$user) { throw ValidationException::withMessages(['code' => 'Kode tidak valid atau kedaluwarsa.']); }
        event(new PasswordReset($user));
        return ['message' => 'Kata sandi telah diubah.'];
    }

    private function consume(int $id, string $purpose, string $code, ?string $password = null, ?string $email = null): ?User
    {
        return DB::transaction(function () use ($id, $purpose, $code, $password, $email) {
            $user = User::whereKey($id)->lockForUpdate()->first();
            if (!$user || ($email !== null && $user->email !== $email)) { return null; }
            $query = DB::table('auth_otps')->where('user_id', $id)->where('purpose', $purpose);
            $otp = $query->lockForUpdate()->first();
            if (!$otp || $otp->email !== $user->email || $otp->attempts >= 5 || Carbon::parse($otp->expires_at)->isPast()) { return null; }
            if (!Hash::check($code, $otp->code)) { $query->increment('attempts'); return null; }
            $query->delete();
            if ($purpose === 'verification') {
                if ($user->hasVerifiedEmail()) { return null; }
                $user->markEmailAsVerified();
            } else {
                if (!$user->hasVerifiedEmail()) { return null; }
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }
            return $user;
        }, 3);
    }
}
