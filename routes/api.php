<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\InteractionController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\PaymentController;

// ─── Public Routes ────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('register',         [AuthController::class, 'register']);
    Route::post('login',            [AuthController::class, 'login']);
    Route::post('forgot-password',  [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',   [AuthController::class, 'resetPassword']);
    Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
         ->name('verification.verify');
});

// Public listings (read-only)
Route::get('listings',          [ListingController::class, 'index']);
Route::get('listings/featured', [ListingController::class, 'featured']);
Route::get('listings/{id}',     [ListingController::class, 'show']);
Route::post('listings/{id}/view', [ListingController::class, 'recordView']);

// ─── Authenticated Routes ─────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me',      [AuthController::class, 'me']);
    Route::post('auth/resend-verification', [AuthController::class, 'resendVerification']);

    // Listings (write)
    Route::post('listings',         [ListingController::class, 'store']);
    Route::post('listings/{id}',    [ListingController::class, 'update']); // POST for FormData
    Route::delete('listings/{id}',  [ListingController::class, 'destroy']);

    // Interactions
    Route::prefix('interactions')->group(function () {
        Route::post('bookmark',               [InteractionController::class, 'bookmark']);
        Route::delete('bookmark/{listingId}', [InteractionController::class, 'removeBookmark']);
        Route::get('bookmarks',               [InteractionController::class, 'getBookmarks']);

        Route::post('bid',          [InteractionController::class, 'submitBid']);
        Route::post('report',       [InteractionController::class, 'report']);
        Route::post('saved-search', [InteractionController::class, 'saveSearch']);
        Route::get('saved-searches',[InteractionController::class, 'getSavedSearches']);
        Route::delete('saved-searches/{id}', [InteractionController::class, 'deleteSavedSearch']);
    });

    // Messages (inbox)
    Route::prefix('messages')->group(function () {
        Route::get('inbox',   [MessageController::class, 'inbox']);
        Route::get('thread',  [MessageController::class, 'thread']);
        Route::post('/',      [MessageController::class, 'send']);
        Route::patch('{id}/read', [MessageController::class, 'markRead']);
    });

    // User profile & dashboard
    Route::prefix('user')->group(function () {
        Route::get('dashboard',         [UserController::class, 'dashboard']);
        Route::get('listings',          [UserController::class, 'myListings']);
        Route::put('profile',           [UserController::class, 'updateProfile']);
        Route::post('verification',     [UserController::class, 'submitVerification']);
        Route::delete('account',        [UserController::class, 'deleteAccount']);
    });

    // Payments
    Route::prefix('payments')->group(function () {
        Route::post('initiate',         [PaymentController::class, 'initiate']);
        Route::get('history',           [PaymentController::class, 'history']);
        Route::post('webhook',          [PaymentController::class, 'webhook']);
    });

    // ─── Admin Routes ─────────────────────────────────────────────────────────

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('dashboard',                 [AdminController::class, 'dashboard']);
        Route::get('users',                     [AdminController::class, 'users']);
        Route::patch('users/{id}/role',         [AdminController::class, 'updateRole']);
        Route::patch('users/{id}/suspend',      [AdminController::class, 'suspend']);
        Route::post('users/{id}/impersonate',   [AdminController::class, 'impersonate']);

        Route::get('verifications',             [AdminController::class, 'pendingVerifications']);
        Route::patch('verifications/{userId}',  [AdminController::class, 'reviewVerification']);

        Route::get('listings/pending',          [AdminController::class, 'pendingListings']);
        Route::patch('listings/{id}/approve',   [AdminController::class, 'approveListing']);
        Route::patch('listings/{id}/reject',    [AdminController::class, 'rejectListing']);

        Route::get('reports',                   [AdminController::class, 'reports']);
        Route::patch('reports/{id}',            [AdminController::class, 'handleReport']);

        Route::patch('users/{id}/trusted-payer', [AdminController::class, 'toggleTrustedPayer']);

        Route::get('revenue',                   [AdminController::class, 'revenue']);
    });
});
