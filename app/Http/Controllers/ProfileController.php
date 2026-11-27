<?php
namespace App\Http\Controllers;
use App\Models\{Address, FarmerVerification, Notice};
use App\Services\AuthOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController
{
    public function __construct(private AuthOtp $otp) {}
    public function show(Request $r) { return ['user' => $r->user(), 'addresses' => Address::where('user_id', $r->user()->id)->get(), 'verification' => FarmerVerification::where('user_id', $r->user()->id)->first()]; }
    public function update(Request $r)
    {
        $email = $r->validate(['email' => ['sometimes', 'required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($r->user()->id)], 'current_password' => 'required_with:email|string']);
        if (isset($email['email']) && $email['email'] !== $r->user()->email) {
            abort_unless(\Illuminate\Support\Facades\Hash::check($email['current_password'], $r->user()->password), 422, 'Kata sandi saat ini tidak sesuai.');
        }
        $v = $r->validate(['name' => 'sometimes|required|string|max:150', 'phone' => 'nullable|string|max:30', 'organization' => 'nullable|string|max:255', 'preferences' => 'sometimes|array:high_contrast,text_scale,reminders,radius_km,latitude,longitude', 'preferences.high_contrast' => 'boolean', 'preferences.text_scale' => 'numeric|min:1|max:2', 'preferences.reminders' => 'boolean', 'preferences.radius_km' => 'numeric|min:1|max:500', 'preferences.latitude' => 'numeric|between:-90,90', 'preferences.longitude' => 'numeric|between:-180,180']);
        if (isset($v['preferences'])) { $v['preferences'] = array_merge($r->user()->preferences ?? [], $v['preferences']); }
        return DB::transaction(function () use ($r, $v, $email) {
            $user = \App\Models\User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $changed = isset($email['email']) && $email['email'] !== $user->email;
            if ($changed) {
                // Recheck under the lock, then invalidate identity-linked credentials.
                abort_unless(\Illuminate\Support\Facades\Hash::check($email['current_password'], $user->password), 422, 'Kata sandi saat ini tidak sesuai.');
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                DB::table('auth_otps')->where('user_id', $user->id)->delete();
                $user->email = $email['email']; $user->email_verified_at = null;
                $user->tokens()->where('id', '!=', $r->user()->currentAccessToken()?->id)->delete();
            }
            $user->fill($v)->save();
            $r->user()->setRawAttributes($user->getAttributes(), true);
            if ($changed) { DB::afterCommit(fn () => $this->otp->send($user, 'verification')); }
            return $user;
        });
    }
    public function address(Request $r, ?Address $address = null)
    {
        if ($address?->exists) { abort_unless($address->user_id === $r->user()->id, 403); }
        $v = $r->validate(['label' => 'required|string|max:100', 'address' => 'required|string|max:2000', 'area' => 'required|string|max:255', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'instructions' => 'nullable|string|max:1000']);
        $address ??= new Address; $address->fill($v); $address->user_id = $r->user()->id; $address->save(); return $address;
    }
    public function verification(Request $r)
    {
        $v = $r->validate(['description' => 'required|string|max:5000']);
        return DB::transaction(function () use ($r, $v) {
            \App\Models\User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $existing = FarmerVerification::where('user_id', $r->user()->id)->first();
            abort_if($existing && in_array($existing->status, ['pending', 'approved']), 409, 'Pengajuan sedang ditinjau atau sudah disetujui.');
            $before = $existing?->getAttributes() ?? [];
            $verification = FarmerVerification::updateOrCreate(['user_id' => $r->user()->id], $v + ['status' => 'pending', 'reason' => null, 'reviewer_id' => null, 'reviewed_at' => null]);
            \App\Services\Activity::audit($verification, 'verification.submitted', $before);
            foreach (\App\Models\User::where('role', 'admin')->pluck('id') as $admin) { \App\Services\Activity::notify($admin, 'Pengajuan verifikasi petani perlu ditinjau.', '/admin/verifications'); }
            return $verification;
        });
    }
    public function notifications(Request $r) { return Notice::where('user_id', $r->user()->id)->latest()->paginate(20); }
    public function read(Request $r, Notice $notice) { abort_unless($notice->user_id === $r->user()->id, 403); $notice->update(['read_at' => now()]); return $notice; }
}
