<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AuthController, ProfileController, OfferController, OrderController, WebhookController, MediaController, AdminController, CommunityController, ReportController, ComplaintController};
use App\Http\Controllers\AuthOtpController;

Route::prefix('v1')->group(function () {
    Route::post('payments/midtrans/notification', WebhookController::class);
    Route::middleware('throttle:api')->group(function () {
        Route::get('categories', fn () => \App\Models\Category::orderBy('name')->get());
        Route::get('offers', [OfferController::class, 'index']);
        Route::get('offers/{offer}', [OfferController::class, 'show']);
        Route::get('media/{media}', [MediaController::class, 'show']);
        Route::middleware('throttle:auth')->prefix('auth')->group(function () {
            Route::post('register', [AuthController::class, 'register']); Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login-account');
            Route::post('forgot-password', [AuthController::class, 'forgot']); Route::post('reset-password', [AuthController::class, 'reset']);
            Route::post('forgot-password/otp', [AuthOtpController::class, 'sendReset']);
            Route::post('reset-password/otp', [AuthOtpController::class, 'reset']);
        });
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('auth/logout', [AuthController::class, 'logout']);
            Route::post('auth/email/verification-notification', [AuthController::class, 'verificationNotice'])->middleware('throttle:auth');
            Route::post('auth/email/otp', [AuthOtpController::class, 'sendVerification'])->middleware('throttle:auth');
            Route::post('auth/email/verify-otp', [AuthOtpController::class, 'verify'])->middleware('throttle:auth');
            Route::get('auth/email/verify/{id}/{hash}', [AuthController::class, 'verify'])->middleware('signed')->name('verification.verify');
            Route::get('me', [ProfileController::class, 'show']); Route::patch('me', [ProfileController::class, 'update']);
            Route::middleware('verified.email')->group(function () {
                Route::post('addresses', [ProfileController::class, 'address']); Route::put('addresses/{address}', [ProfileController::class, 'address']);
                Route::get('notifications', [ProfileController::class, 'notifications']); Route::patch('notifications/{notice}/read', [ProfileController::class, 'read']);
                Route::post('media', [MediaController::class, 'store']);
                Route::get('orders', [OrderController::class, 'index']); Route::get('orders/{order}', [OrderController::class, 'show']);
                Route::post('orders/{order}/sync-payment', [OrderController::class, 'sync']); Route::post('orders/{order}/complaints', [OrderController::class, 'complaint']);
                Route::get('complaints/{complaint}', [ComplaintController::class, 'show']); Route::post('complaints/{complaint}/messages', [ComplaintController::class, 'message']);
                Route::get('needs', [CommunityController::class, 'needs']); Route::get('needs/{need}/responses', [CommunityController::class, 'responses']);
                Route::middleware('role:buyer')->group(function () {
                    Route::post('orders', [OrderController::class, 'store']); Route::post('orders/{order}/payment', [OrderController::class, 'snap']);
                    Route::post('orders/{order}/receive', [OrderController::class, 'receive']); Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
                    Route::post('orders/{order}/pickup-decision', [OrderController::class, 'scheduleDecision']);
                    Route::get('favorites', [CommunityController::class, 'favorites']); Route::post('favorites', [CommunityController::class, 'favorite']); Route::delete('favorites/{favorite}', [CommunityController::class, 'unfavorite']);
                    Route::post('needs', [CommunityController::class, 'need']); Route::patch('needs/{need}', [CommunityController::class, 'closeNeed']); Route::post('needs/{need}/responses/{response}/select', [CommunityController::class, 'select']);
                });
                Route::middleware('role:farmer')->prefix('farmer')->group(function () {
                    Route::post('verification', [ProfileController::class, 'verification']); Route::get('offers', [OfferController::class, 'mine']);
                    Route::post('offers', [OfferController::class, 'store']); Route::put('offers/{offer}', [OfferController::class, 'update']);
                    Route::patch('offers/{offer}/inventory', [OfferController::class, 'inventory']); Route::post('offers/{offer}/confirm-resale', [OfferController::class, 'confirmResale']); Route::get('offers/{offer}/price-preview', [OfferController::class, 'pricePreview']);
                    Route::post('offers/{offer}/publish', [OfferController::class, 'publish']); Route::post('offers/{offer}/close', [OfferController::class, 'close']); Route::post('offers/{offer}/duplicate', [OfferController::class, 'duplicate']);
                    Route::post('orders/{order}/ready', [OrderController::class, 'ready']); Route::post('orders/{order}/propose-pickup', [OrderController::class, 'propose']);
                    Route::post('needs/{need}/responses', [CommunityController::class, 'respond']); Route::get('reports', [ReportController::class, 'index']);
                });
                Route::middleware('role:admin')->prefix('admin')->group(function () {
                    Route::get('verifications', [AdminController::class, 'verifications']); Route::patch('verifications/{verification}', [AdminController::class, 'review']);
                    Route::get('complaints', [AdminController::class, 'complaints']); Route::patch('complaints/{complaint}', [AdminController::class, 'respond']);
                    Route::post('offers/{offer}/close', [OfferController::class, 'close']); Route::post('orders/{order}/resolve', [AdminController::class, 'resolve']); Route::put('orders/{order}/refund', [AdminController::class, 'refund']);
                    Route::post('orders/{order}/cancel-payment', [AdminController::class, 'cancelPayment']);
                    Route::post('orders/{order}/correct-distribution', [AdminController::class, 'correctDistribution']);
                    Route::post('offers/{offer}/release-moderation', [AdminController::class, 'releaseModeration']);
                    Route::get('emission-factors', [AdminController::class, 'factors']); Route::post('emission-factors', [AdminController::class, 'factor']);
                    Route::get('reports', [ReportController::class, 'index']); Route::get('reports/export', [ReportController::class, 'export']); Route::get('audit-logs', [AdminController::class, 'audit']);
                });
            });
        });
    });
});
