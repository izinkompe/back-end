<?php
namespace App\Http\Controllers;
use App\Models\{Address, FarmerVerification, Notice};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController
{
    public function show(Request $r) { return ['user' => $r->user(), 'addresses' => Address::where('user_id', $r->user()->id)->get(), 'verification' => FarmerVerification::where('user_id', $r->user()->id)->first()]; }
    public function update(Request $r)
    {
        $v = $r->validate(['name' => 'sometimes|required|string|max:150', 'phone' => 'nullable|string|max:30', 'organization' => 'nullable|string|max:255', 'preferences' => 'sometimes|array:high_contrast,text_scale,reminders,radius_km,latitude,longitude', 'preferences.high_contrast' => 'boolean', 'preferences.text_scale' => 'numeric|min:1|max:2', 'preferences.reminders' => 'boolean', 'preferences.radius_km' => 'numeric|min:1|max:500', 'preferences.latitude' => 'numeric|between:-90,90', 'preferences.longitude' => 'numeric|between:-180,180']);
        if (isset($v['preferences'])) { $v['preferences'] = array_merge($r->user()->preferences ?? [], $v['preferences']); }
        $r->user()->update($v); return $r->user();
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
            return FarmerVerification::updateOrCreate(['user_id' => $r->user()->id], $v + ['status' => 'pending', 'reason' => null, 'reviewer_id' => null, 'reviewed_at' => null]);
        });
    }
    public function notifications(Request $r) { return Notice::where('user_id', $r->user()->id)->latest()->paginate(20); }
    public function read(Request $r, Notice $notice) { abort_unless($notice->user_id === $r->user()->id, 403); $notice->update(['read_at' => now()]); return $notice; }
}
