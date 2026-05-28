<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Central subscription logic.
 *
 *   - getCurrent(user)   → active sub OR auto-grant Free on first call
 *   - getUsage(user)     → current-month counter (auto-creates row)
 *   - canPostListing()   → respects max_listings + section restrictions
 *   - incrementListings()→ called right after a listing is successfully created
 *   - grant(user, plan)  → admin-grant or future Moyasar webhook
 *   - cancel(sub)        → mark cancelled (still active until expires_at)
 *
 * Side-effects:
 *   - Plans are cached for the request lifecycle via Laravel container
 *   - Free plan is auto-granted exactly once per user; subsequent calls are
 *     idempotent
 */
class SubscriptionService
{
    private const FREE_CODE = 'free';

    /** @var array<string, Plan>|null */
    private ?array $planCache = null;

    /**
     * Returns the user's currently active subscription (loaded with plan).
     * If the user has none, auto-grants the Free plan and returns it.
     */
    public function getCurrent(User|int $user): Subscription
    {
        $userId = $user instanceof User ? $user->id : (int) $user;

        $sub = Subscription::with('plan')
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'pending'])
            ->orderByDesc('id')
            ->first();

        if ($sub) return $sub;

        return $this->grantFreePlan($userId);
    }

    /**
     * Idempotent: returns existing usage row for the current month, or
     * creates a zeroed one.
     */
    public function getUsage(User|int $user, ?string $period = null): SubscriptionUsage
    {
        $userId = $user instanceof User ? $user->id : (int) $user;
        $period ??= now()->format('Y-m');

        return SubscriptionUsage::firstOrCreate(
            ['user_id' => $userId, 'period_yyyymm' => $period],
            ['listings_posted' => 0, 'featured_used' => 0, 'pins_used' => 0]
        );
    }

    /**
     * Returns ['allowed' => bool, 'reason' => ?string, 'limit' => ?int, 'used' => ?int].
     * Pass $section so we can reject e.g. ma listings for plans that don't have it.
     */
    public function canPostListing(User|int $user, string $section = 'fleet'): array
    {
        $sub   = $this->getCurrent($user);
        $plan  = $sub->plan;
        $usage = $this->getUsage($user);

        // Section gate: M&A requires has_ma feature
        if ($section === 'ma' && !$plan->feature('has_ma', false)) {
            return [
                'allowed' => false,
                'reason'  => 'plan_blocks_ma',
                'message' => 'إعلانات الاستحواذ والاندماج متاحة لباقات Basic فأعلى.',
                'limit'   => null,
                'used'    => null,
            ];
        }

        // Listing count gate
        $max = (int) $plan->feature('max_listings', 3);
        if ($max === -1) {
            return ['allowed' => true, 'reason' => null, 'limit' => null, 'used' => $usage->listings_posted];
        }
        if ($usage->listings_posted >= $max) {
            return [
                'allowed' => false,
                'reason'  => 'monthly_limit_reached',
                'message' => "وصلت لحدّ الإعلانات الشهري لباقتك ({$max}). ترقّى لباقة أعلى للمزيد.",
                'limit'   => $max,
                'used'    => $usage->listings_posted,
            ];
        }

        return [
            'allowed' => true,
            'reason'  => null,
            'limit'   => $max,
            'used'    => $usage->listings_posted,
        ];
    }

    /**
     * Bump the monthly counter — call this AFTER a listing was successfully created.
     * Wrapped in a row-level update to be safe under concurrent posts.
     */
    public function incrementListings(User|int $user): void
    {
        $usage = $this->getUsage($user);
        $usage->increment('listings_posted');
    }

    /**
     * Grant a plan to a user. Cancels any existing active sub first.
     * `$cycle` = 'monthly' | 'yearly'.
     */
    public function grant(
        User|int $user,
        Plan|string|int $plan,
        string $cycle = 'monthly',
        ?int $grantedByAdminId = null,
        string $source = 'admin_grant'
    ): Subscription {
        $userId = $user instanceof User ? $user->id : (int) $user;
        $planModel = $this->resolvePlan($plan);

        return DB::transaction(function () use ($userId, $planModel, $cycle, $grantedByAdminId, $source) {
            // Mark any existing active sub as superseded
            Subscription::where('user_id', $userId)
                ->whereIn('status', ['active', 'pending'])
                ->update([
                    'status'       => 'cancelled',
                    'cancelled_at' => now(),
                ]);

            $months = $cycle === 'yearly' ? 12 : 1;

            return Subscription::create([
                'user_id'       => $userId,
                'plan_id'       => $planModel->id,
                'status'        => 'active',
                'billing_cycle' => $cycle,
                'started_at'    => now(),
                'expires_at'    => $planModel->code === self::FREE_CODE
                    ? null    // free plan never expires
                    : now()->addMonths($months),
                'auto_renew'    => $source !== 'admin_grant',
                'source'        => $source,
                'granted_by'    => $grantedByAdminId,
            ]);
        });
    }

    /**
     * Cancel auto-renewal but keep the plan active until expires_at.
     */
    public function cancel(Subscription $sub): Subscription
    {
        $sub->update([
            'auto_renew'   => false,
            'cancelled_at' => now(),
            'status'       => $sub->expires_at && $sub->expires_at->isFuture()
                ? 'cancelled'     // still entitled until expiry
                : 'expired',
        ]);
        return $sub;
    }

    private function grantFreePlan(int $userId): Subscription
    {
        $free = $this->getPlanByCode(self::FREE_CODE);
        return Subscription::create([
            'user_id'       => $userId,
            'plan_id'       => $free->id,
            'status'        => 'active',
            'billing_cycle' => 'monthly',
            'started_at'    => now(),
            'expires_at'    => null,    // free is forever
            'auto_renew'    => false,
            'source'        => 'auto_grant',
        ])->load('plan');
    }

    private function getPlanByCode(string $code): Plan
    {
        if ($this->planCache === null) {
            $this->planCache = Plan::where('is_active', true)->get()->keyBy('code')->all();
        }
        if (!isset($this->planCache[$code])) {
            // Cold-load: caller may have just inserted a new plan
            $plan = Plan::where('code', $code)->firstOrFail();
            $this->planCache[$code] = $plan;
        }
        return $this->planCache[$code];
    }

    private function resolvePlan(Plan|string|int $plan): Plan
    {
        if ($plan instanceof Plan) return $plan;
        if (is_int($plan))         return Plan::findOrFail($plan);
        return $this->getPlanByCode($plan);
    }
}
