<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\InteractionController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AdminController;

// ─── Public Routes ────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [AuthController::class, 'resetPassword']);
    Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
         ->name('verification.verify');
});

// Public listings (read-only)
Route::get('listings/featured', [ListingController::class, 'featured']); // before {id}
Route::get('listings',          [ListingController::class, 'index']);
Route::get('listings/{id}',     [ListingController::class, 'show']);
Route::post('listings/{id}/view', [ListingController::class, 'recordView']);

// ─── Authenticated Routes ─────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('auth/logout',              [AuthController::class, 'logout']);
    Route::get('auth/me',                   [AuthController::class, 'me']);
    Route::post('auth/resend-verification', [AuthController::class, 'resendVerification']);

    // Listings (write)
    Route::post('listings',        [ListingController::class, 'store']);
    Route::post('listings/{id}',   [ListingController::class, 'update']);  // POST for FormData (_method=PUT)
    Route::delete('listings/{id}', [ListingController::class, 'destroy']);

    // Per-listing interactions (RESTful on listing resource)
    Route::post('listings/{id}/bookmark',  [InteractionController::class, 'bookmark']);
    Route::delete('listings/{id}/bookmark',[InteractionController::class, 'removeBookmark']);
    Route::post('listings/{id}/bid',       [InteractionController::class, 'submitBid']);
    Route::post('listings/{id}/report',    [InteractionController::class, 'report']);

    // Bookmarks list
    Route::get('bookmarks', [InteractionController::class, 'myBookmarks']);

    // Saved searches
    Route::get('saved-searches',        [InteractionController::class, 'mySavedSearches']);
    Route::post('saved-searches',       [InteractionController::class, 'saveSearch']);
    Route::delete('saved-searches/{id}',[InteractionController::class, 'deleteSavedSearch']);

    // Messages
    Route::get('messages',                     [MessageController::class, 'inbox']);
    Route::get('messages/{userId}/{listingId}', [MessageController::class, 'thread']);
    Route::post('messages',                    [MessageController::class, 'send']);

    // User profile & dashboard
    Route::prefix('user')->group(function () {
        Route::get('dashboard',                [UserController::class, 'dashboard']);
        Route::get('listings',                 [UserController::class, 'myListings']);
        Route::put('profile',                  [UserController::class, 'updateProfile']);
        Route::post('business-verification',   [UserController::class, 'uploadBusinessVerification']);
        Route::delete('account',               [UserController::class, 'deleteAccount']);
    });

    // ─── Admin Routes ─────────────────────────────────────────────────────────

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('dashboard',                      [AdminController::class, 'dashboard']);

        // Users
        Route::get('users',                          [AdminController::class, 'users']);
        Route::patch('users/{id}/trusted-payer',     [AdminController::class, 'toggleTrustedPayer']);

        // Business verifications
        Route::get('verifications',                  [AdminController::class, 'pendingVerifications']);
        Route::post('verifications/{id}/approve',    [AdminController::class, 'approveVerification']);
        Route::post('verifications/{id}/reject',     [AdminController::class, 'rejectVerification']);

        // Listings moderation
        Route::get('listings',                       [AdminController::class, 'listings']);
        Route::patch('listings/{id}/approve',        [AdminController::class, 'approveListing']);
        Route::patch('listings/{id}/reject',         [AdminController::class, 'rejectListing']);
        Route::patch('listings/{id}/feature',        [AdminController::class, 'toggleFeatured']);

        // Reports
        Route::get('reports',                        [AdminController::class, 'reports']);
        Route::patch('reports/{id}/resolve',         [AdminController::class, 'resolveReport']);

        // Revenue
        Route::get('revenue',                        [AdminController::class, 'revenue']);
    });
});
