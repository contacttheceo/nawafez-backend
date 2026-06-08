<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\User;
use App\Services\AdminEmailService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Sends a daily activity digest email to every admin user.
 *
 * Schedule: 08:00 Asia/Riyadh (configured in routes/console.php).
 * Numbers reflect the previous 24 hours (today not included — we want
 * a clean "yesterday's activity" snapshot when the admin opens it
 * in the morning).
 */
class AdminDailyDigest extends Command
{
    protected $signature   = 'admin:daily-digest {--dry-run : log instead of sending}';
    protected $description = 'Send a daily activity digest to all admins';

    public function handle(AdminEmailService $emails): int
    {
        $now    = now();
        $window = $now->copy()->subDay();

        // Arabic weekday + date (e.g. "الإثنين 8 يونيو 2026")
        $weekdays = [
            'Sunday' => 'الأحد', 'Monday' => 'الإثنين', 'Tuesday' => 'الثلاثاء',
            'Wednesday' => 'الأربعاء', 'Thursday' => 'الخميس',
            'Friday' => 'الجمعة', 'Saturday' => 'السبت',
        ];
        $months = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];
        $dateLabel = sprintf(
            '%s %d %s %d',
            $weekdays[$now->format('l')] ?? $now->format('l'),
            $now->day,
            $months[$now->month] ?? $now->format('F'),
            $now->year
        );

        // — Counts in the last 24h —
        $newUsers    = User::where('created_at', '>=', $window)->count();
        $newListings = Listing::where('created_at', '>=', $window)->count();

        // Breakdown of *new* listings by section
        $sectionBreakdown = Listing::where('created_at', '>=', $window)
            ->selectRaw('section, COUNT(*) as c')
            ->groupBy('section')
            ->pluck('c', 'section')
            ->toArray();

        // — Snapshot —
        $totalUsers   = User::count();
        $totalActive  = Listing::where('status', 'active')->count();
        $pendingReview = Listing::where('status', 'pending_review')->count();

        // Approx 24h listing views: we don't have a per-day events table yet,
        // so we report the current views_count sum *delta* approximation by
        // showing total views accumulated by listings created in the window
        // plus the 5 most-viewed listings overall (gives admin a feel).
        $listingViews24h = (int) Listing::where('updated_at', '>=', $window)
            ->sum('views_count');

        // Top 5 listings by views (lifetime — proxy for "what's hot")
        $topListings = Listing::where('status', 'active')
            ->orderByDesc('views_count')
            ->take(5)
            ->get(['id', 'title_ar', 'title_en', 'views_count']);

        $stats = [
            'date_label'             => $dateLabel,
            'new_users'              => $newUsers,
            'new_listings'           => $newListings,
            'pending_review'         => $pendingReview,
            'listing_views_24h'      => $listingViews24h,
            'total_users'            => $totalUsers,
            'total_active_listings'  => $totalActive,
            'section_breakdown'      => $sectionBreakdown,
            'top_listings'           => $topListings,
        ];

        if ($this->option('dry-run')) {
            $this->line('Dry run — stats:');
            $this->line(json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $emails->adminDailyDigest($stats);
        $this->info("Daily digest sent. new_users={$newUsers}, new_listings={$newListings}, pending={$pendingReview}");
        return self::SUCCESS;
    }
}
