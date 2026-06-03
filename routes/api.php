<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\InteractionController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\CommentInteractionController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\AdminSubscriptionController;

// ─── Public Routes ────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [AuthController::class, 'resetPassword']);
    Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
         ->name('verification.verify');
});

// Public stats
Route::get('stats', [StatsController::class, 'index']);

// Public — list available subscription plans (for /pricing page)
Route::get('plans', [SubscriptionController::class, 'plans']);

// Public listings (read-only)
Route::get('listings/featured',      [ListingController::class, 'featured']); // before {id}
Route::get('listings',               [ListingController::class, 'index']);
Route::get('listings/{id}',          [ListingController::class, 'show']);
Route::post('listings/{id}/view',    [ListingController::class, 'recordView']);
Route::get('listings/{id}/bids',     [InteractionController::class, 'getBids']);  // public summary; owner gets full list
Route::get('listings/{id}/comments', [CommentController::class, 'index']);         // public read

// ─── Authenticated Routes ─────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('auth/logout',              [AuthController::class, 'logout']);
    Route::get('auth/me',                   [AuthController::class, 'me']);
    Route::post('auth/resend-verification', [AuthController::class, 'resendVerification']);

    // ─── Verified-only routes: publishing actions require verified email ───
    Route::middleware('verified')->group(function () {
        // Listings (write)
        Route::post('listings',              [ListingController::class, 'store']);
        Route::post('listings/{id}',         [ListingController::class, 'update']);   // POST for FormData
        Route::patch('listings/{id}/renew',  [ListingController::class, 'renew']);

        // Bids — submitting an offer also requires verification
        Route::post('listings/{id}/bid',     [InteractionController::class, 'submitBid']);

        // Comments — posting new comments
        Route::post('listings/{id}/comments',         [CommentController::class, 'store']);
    });
    // ─── End verified-only ───

    // Read/own-management actions OK without verification:
    Route::delete('listings/{id}',       [ListingController::class, 'destroy']);
    Route::patch('listings/{id}/pause',  [ListingController::class, 'pause']);
    Route::patch('listings/{id}/unpause',[ListingController::class, 'unpause']);

    // Per-listing interactions (bookmarks, reports — low-risk)
    Route::post('listings/{id}/bookmark',  [InteractionController::class, 'bookmark']);
    Route::delete('listings/{id}/bookmark',[InteractionController::class, 'removeBookmark']);
    Route::post('listings/{id}/report',    [InteractionController::class, 'report']);

    // Deleting own comment also OK
    Route::delete('listings/{id}/comments/{commentId}',  [CommentController::class, 'destroy']);

    // Comment interactions (Q&A forum)
    Route::post('comments/{id}/vote',           [CommentInteractionController::class, 'toggleVote']);
    Route::post('comments/{id}/mark-helpful',   [CommentInteractionController::class, 'markHelpful']);
    Route::post('comments/{id}/unmark-helpful', [CommentInteractionController::class, 'unmarkHelpful']);
    Route::post('comments/{id}/report',         [CommentInteractionController::class, 'report']);

    // AI
    Route::post('ai/write-listing', [AiController::class, 'writeListing']);

    // Bookmarks list
    Route::get('bookmarks', [InteractionController::class, 'myBookmarks']);

    // Saved searches
    Route::get('saved-searches',        [InteractionController::class, 'mySavedSearches']);
    Route::post('saved-searches',       [InteractionController::class, 'saveSearch']);
    Route::delete('saved-searches/{id}',[InteractionController::class, 'deleteSavedSearch']);

    // Messages
    Route::get('messages',                     [MessageController::class, 'inbox']);
    Route::get('messages/{userId}/{listingId}', [MessageController::class, 'thread']);
    Route::post('messages',                    [MessageController::class, 'send'])->middleware('verified');

    // User profile & dashboard
    Route::prefix('user')->group(function () {
        Route::get('dashboard',                [UserController::class, 'dashboard']);
        Route::get('listings',                 [UserController::class, 'myListings']);
        Route::get('bids',                     [UserController::class, 'myBids']);
        Route::put('profile',                  [UserController::class, 'updateProfile']);
        Route::post('avatar',                  [UserController::class, 'uploadAvatar']);
        Route::delete('avatar',                [UserController::class, 'deleteAvatar']);
        Route::post('business-verification',   [UserController::class, 'uploadBusinessVerification']);
        Route::delete('account',               [UserController::class, 'deleteAccount']);

        // Web Push subscriptions (one row per browser/device per user)
        Route::get('push-subscriptions',       [PushSubscriptionController::class, 'index']);
        Route::post('push-subscriptions',      [PushSubscriptionController::class, 'store']);
        Route::delete('push-subscriptions',    [PushSubscriptionController::class, 'destroy']);

        // Current plan + usage + upgrade requests
        Route::get('subscription',                 [SubscriptionController::class, 'current']);
        Route::post('subscription/upgrade-request',[SubscriptionController::class, 'requestUpgrade']);
        Route::post('subscription/cancel',         [SubscriptionController::class, 'cancel']);
    });

    // ─── Admin Routes ─────────────────────────────────────────────────────────

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('dashboard',                      [AdminController::class, 'dashboard']);

        // Users
        Route::get('users',                          [AdminController::class, 'users']);
        Route::patch('users/{id}/trusted-payer',     [AdminController::class, 'toggleTrustedPayer']);
        Route::patch('users/{id}/verify-email',      [AdminController::class, 'manuallyVerifyEmail']);
        Route::post ('users/{id}/resend-verification', [AdminController::class, 'adminResendVerification']);
        Route::post ('users/{id}/activate',          [AdminController::class, 'activateAccount']);
        Route::patch('users/{id}/suspend',           [AdminController::class, 'suspendUser']);
        Route::patch('users/{id}/unsuspend',         [AdminController::class, 'unsuspendUser']);
        Route::delete('users/{id}',                  [AdminController::class, 'deleteUser']);

        // Business verifications
        Route::get('verifications',                  [AdminController::class, 'pendingVerifications']);
        Route::post('verifications/{id}/approve',    [AdminController::class, 'approveVerification']);
        Route::post('verifications/{id}/reject',     [AdminController::class, 'rejectVerification']);

        // Listings moderation
        Route::get('listings',                       [AdminController::class, 'listings']);
        Route::patch('listings/{id}/approve',        [AdminController::class, 'approveListing']);
        Route::patch('listings/{id}/reject',         [AdminController::class, 'rejectListing']);
        Route::patch('listings/{id}/feature',        [AdminController::class, 'toggleFeatured']);
        Route::post('listings/bulk-approve',         [AdminController::class, 'bulkApproveListings']);
        Route::post('listings/bulk-reject',          [AdminController::class, 'bulkRejectListings']);

        // Reports
        Route::get('reports',                        [AdminController::class, 'reports']);
        Route::patch('reports/{id}/resolve',         [AdminController::class, 'resolveReport']);

        // Revenue
        Route::get('revenue',                        [AdminController::class, 'revenue']);

        // Analytics
        Route::get('analytics',                      [AdminController::class, 'analytics']);

        // Subscriptions & Plans
        Route::get('subscriptions',                  [AdminSubscriptionController::class, 'index']);
        Route::get('subscriptions/pending',          [AdminSubscriptionController::class, 'pending']);
        Route::patch('subscriptions/{id}',           [AdminSubscriptionController::class, 'update']);
        Route::post('users/{id}/grant-plan',         [AdminSubscriptionController::class, 'grantPlan']);
        Route::get('plans',                          [AdminSubscriptionController::class, 'plansIndex']);
        Route::patch('plans/{id}',                   [AdminSubscriptionController::class, 'plansUpdate']);

        // Audit log
        Route::get('audit-logs',                     [AdminController::class, 'auditLogs']);

        // Comments moderation
        Route::get   ('comments',                            [AdminController::class, 'comments']);
        Route::delete('comments/{id}',                       [AdminController::class, 'deleteComment']);
        Route::post  ('comments/{id}/mark-official',         [CommentInteractionController::class, 'markOfficial']);
        Route::post  ('comments/{id}/unmark-official',       [CommentInteractionController::class, 'unmarkOfficial']);
    });
});
