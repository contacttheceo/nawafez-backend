<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\User;
use App\Services\AdminEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drips onboarding emails to users on day 2, 5, 9, and 14 after signup.
 *
 * Schedule: daily at 10:00 Asia/Riyadh (routes/console.php).
 * Skips unverified accounts (handled inside AdminEmailService methods).
 *
 * Tracks which emails were already sent via a tiny journal in the
 * `email_log` table (or a JSON column on the user — we use a separate
 * table to avoid touching users.* schema).
 */
class OnboardingSequence extends Command
{
    protected $signature   = 'onboarding:send {--dry-run : log instead of sending}';
    protected $description = 'Drip onboarding emails to users on day 2/5/9/14 after signup';

    public function handle(AdminEmailService $emails): int
    {
        $this->ensureLogTable();

        $stages = [
            ['day' => 2,  'key' => 'nudge',        'method' => 'onboardingNudge',        'cond' => 'no_listings'],
            ['day' => 5,  'key' => 'educational',  'method' => 'onboardingEducational',  'cond' => null],
            ['day' => 9,  'key' => 'social_proof', 'method' => 'onboardingSocialProof',  'cond' => null],
            ['day' => 14, 'key' => 'feedback',     'method' => 'onboardingFeedback',     'cond' => null],
        ];

        $totalSent = 0;
        foreach ($stages as $s) {
            $targetDay = now()->subDays($s['day'])->startOfDay();
            $nextDay   = $targetDay->copy()->addDay();

            $users = User::whereBetween('created_at', [$targetDay, $nextDay])
                ->whereNotNull('email')
                ->whereNotNull('email_verified_at')
                ->get();

            foreach ($users as $user) {
                if ($this->wasSent($user->id, $s['key'])) continue;

                // Stage-specific conditions
                if ($s['cond'] === 'no_listings') {
                    $hasListing = Listing::where('user_id', $user->id)->exists();
                    if ($hasListing) {
                        $this->markSent($user->id, $s['key'], 'skipped:has_listings');
                        continue;
                    }
                }

                if ($this->option('dry-run')) {
                    $this->line("DRY: would send {$s['key']} → {$user->email}");
                    continue;
                }

                try {
                    $emails->{$s['method']}($user);
                    $this->markSent($user->id, $s['key'], 'sent');
                    $totalSent++;
                } catch (\Throwable $e) {
                    \Log::warning("onboarding {$s['key']} failed for user {$user->id}: " . $e->getMessage());
                    $this->markSent($user->id, $s['key'], 'failed:' . substr($e->getMessage(), 0, 100));
                }
            }
        }

        $this->info("Onboarding sequence done. Sent {$totalSent} emails.");
        return self::SUCCESS;
    }

    private function ensureLogTable(): void
    {
        if (\Schema::hasTable('onboarding_email_log')) return;
        \Schema::create('onboarding_email_log', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('stage_key', 32);
            $t->string('status', 100)->default('sent');
            $t->timestamp('sent_at')->useCurrent();
            $t->unique(['user_id', 'stage_key']);
        });
    }

    private function wasSent(int $userId, string $stageKey): bool
    {
        return DB::table('onboarding_email_log')
            ->where('user_id', $userId)
            ->where('stage_key', $stageKey)
            ->exists();
    }

    private function markSent(int $userId, string $stageKey, string $status): void
    {
        DB::table('onboarding_email_log')->insertOrIgnore([
            'user_id'   => $userId,
            'stage_key' => $stageKey,
            'status'    => $status,
            'sent_at'   => now(),
        ]);
    }
}
