<?php
namespace App\Http\Controllers;
use App\Models\{Media, Offer, FarmerVerification, Complaint, Refund, ComplaintMessage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController
{
    public function store(Request $r)
    {
        $v = $r->validate(['kind' => 'required|in:offer_photo,verification,complaint,refund,clarification', 'target_id' => 'required|integer', 'file' => 'required|file|mimes:jpg,jpeg,png|mimetypes:image/jpeg,image/png|max:5120']);
        $class = match ($v['kind']) { 'offer_photo' => Offer::class, 'verification' => FarmerVerification::class, 'complaint' => Complaint::class, 'refund' => Refund::class, 'clarification' => ComplaintMessage::class };
        $target = $class::findOrFail($v['target_id']);
        $owner = match ($v['kind']) { 'offer_photo' => $target->farmer_id, 'verification', 'complaint', 'clarification' => $target->user_id, 'refund' => null };
        abort_unless($v['kind'] === 'refund' ? $r->user()->role === 'admin' : $owner === $r->user()->id, 403);
        $file = $r->file('file');
        $image = @getimagesize($file->getRealPath());
        abort_unless($image && in_array($image[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true), 422, 'Isi berkas bukan gambar JPEG/PNG yang valid.');
        $path = $file->store('uploads', 'local');
        try { return Media::create(['user_id' => $r->user()->id, 'kind' => $v['kind'], 'attachable_type' => $class, 'attachable_id' => $target->id, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize()]); }
        catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
    }
    public function show(Request $r, Media $media)
    {
        // Optional bearer authentication: public images stay public; evidence always needs authorization.
        $user = auth('sanctum')->user();
        if ($media->kind === 'offer_photo') {
            $offer = $media->attachable;
            abort_unless($offer && ($offer->status !== 'draft' || ($user && $user->hasVerifiedEmail() && ($user->role === 'admin' || $offer->farmer_id === $user->id))), 404);
        } else {
            abort_unless($user, 401);
            abort_unless($user->hasVerifiedEmail(), 403, 'Email harus diverifikasi.');
            $allowed = $user->role === 'admin' || $media->user_id === $user->id;
            if (in_array($media->kind, ['complaint', 'refund', 'clarification'])) {
                $orderId = $media->kind === 'clarification' ? Complaint::find($media->attachable?->complaint_id)?->order_id : $media->attachable?->order_id;
                $order = \App\Models\Order::find($orderId);
                $allowed = $allowed || ($order && in_array($user->id, [$order->buyer_id, $order->farmer_id], true));
            }
            abort_unless($allowed, 403);
        }
        return response()->file(Storage::disk('local')->path($media->path), ['Content-Type' => $media->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $media->kind === 'offer_photo' ? 'public, max-age=300' : 'private, no-store']);
    }
}
