<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthOtp
{
    public function send(User $user, string $purpose): void
    {
        $code = (string) random_int(100000, 999999);
        $recipient = DB::transaction(function () use ($user, $purpose, $code) {
            $user = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$user || ($purpose === 'reset' && !$user->hasVerifiedEmail()) || ($purpose === 'verification' && $user->hasVerifiedEmail())) { return null; }
            $existing = DB::table('auth_otps')->where('user_id', $user->id)->where('purpose', $purpose)->first();
            if ($existing && Carbon::parse($existing->created_at)->addMinute()->isFuture()) { return null; }
            DB::table('auth_otps')->updateOrInsert(['user_id' => $user->id, 'purpose' => $purpose], [
                'email' => $user->email, 'code' => Hash::make($code), 'attempts' => 0,
                'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);
            return $user->email;
        }, 3);
        if ($recipient) {
            $subject = $purpose === 'reset' ? 'Kode reset kata sandi PanganTani' : 'Kode verifikasi email PanganTani';
            Mail::raw("Kode OTP Anda: {$code}. Kode berlaku selama 10 menit.", fn ($mail) => $mail->to($recipient)->subject($subject));
        }
    }
}
