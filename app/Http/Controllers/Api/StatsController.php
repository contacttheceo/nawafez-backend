<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'total_listings' => Listing::active()->count(),
            'total_users'    => User::count(),
            'sections'       => [
                'ma'        => Listing::active()->section('ma')->count(),
                'fleet'     => Listing::active()->section('fleet')->count(),
                'contracts' => Listing::active()->section('contracts')->count(),
                'jobs'      => Listing::active()->section('jobs')->count(),
                'forum'     => Listing::active()->section('forum')->count(),
            ],
        ]);
    }
}
