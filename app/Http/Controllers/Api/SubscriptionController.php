<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subs)
    {
    }

    /**
     * GET /api/plans — public list of all active plans (for /pricing page).
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::where('is_active', true)
            ->orderBy('display_order')
            ->get();

        return response()->json([
            'data'        => $plans,
            'enforcement' => [
                'mode'         => env('SUBSCRIPTION_ENFORCEMENT', 'off'),
                'grace_period' => env('SUBSCRIPTION_ENFORCEMENT', 'off') !== 'on',
            ],
        ]);
    }

    /**
     * GET /api/user/subscription — current sub + usage + plan details.
     */
    public function current(Request $request): JsonResponse
    {
        $user  = $request->user();
        $sub   = $this->subs->getCurrent($user);
        $usage = $this->subs->getUsage($user);

        return response()->json([
            'data' => [
                'subscription' => $sub,
                'plan'         => $sub->plan,
                'usage'        => [
                    'period_yyyymm'   => $usage->period_yyyymm,
                    'listings_posted' => $usage->listings_posted,
                    'featured_used'   => $usage->featured_used,
                    'pins_used'       => $usage->pins_used,
                ],
                'limits' => [
                    'max_listings'    => $sub->plan->feature('max_listings'),
                    'remaining'       => $this->remaining($sub->plan->feature('max_listings'), $usage->listings_posted),
                    'days_until_expiry' => $sub->daysUntilExpiry(),
                ],
                'enforcement' => [
                    // 'off' = grace period: all features free, no limits enforced
                    // 'on'  = production: limits enforced, /pricing actively sells
                    'mode'           => env('SUBSCRIPTION_ENFORCEMENT', 'off'),
                    'grace_period'   => env('SUBSCRIPTION_ENFORCEMENT', 'off') !== 'on',
                ],
            ],
        ]);
    }

    /**
     * POST /api/user/subscription/upgrade-request
     *
     * Phase 1 (no payment gateway yet): just records the user's intent.
     * Admin sees it in their dashboard and grants the plan manually.
     * Phase 4 will replace this with a Moyasar payment flow.
     */
    public function requestUpgrade(Request $request): JsonResponse
    {
        $this->validate($request, [
            'plan_code'     => ['required', 'string', 'exists:plans,code'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $plan = Plan::where('code', $request->plan_code)->firstOrFail();

        // Free plan can be requested but auto-granted (no admin action needed)
        if ($plan->code === 'free') {
            $sub = $this->subs->grant($user, $plan, 'monthly', null, 'auto_grant');
            return response()->json([
                'message' => 'تم الرجوع للباقة المجانية',
                'data'    => $sub->fresh('plan'),
            ]);
        }

        // Record as pending — admin will grant after payment confirmation
        $sub = \App\Models\Subscription::create([
            'user_id'       => $user->id,
            'plan_id'       => $plan->id,
            'status'        => 'pending',
            'billing_cycle' => $request->billing_cycle,
            'auto_renew'    => true,
            'source'        => 'upgrade_request',
            'metadata'      => [
                'notes'      => $request->notes,
                'requested_at' => now()->toIso8601String(),
            ],
        ]);

        // Web Push — notify all admins so they can action it from /ar/admin
        try {
            $adminIds = \App\Models\User::where('role', 'admin')->pluck('id')->all();
            if (!empty($adminIds)) {
                $planLabel = $plan->name_ar ?? $plan->name_en ?? $plan->code;
                $cycleAr   = $request->billing_cycle === 'yearly' ? 'سنوي' : 'شهري';
                app(\App\Services\PushNotificationService::class)->notifyUsers($adminIds, [
                    'title' => 'طلب اشتراك جديد ✨',
                    'body'  => "{$user->name_ar} يطلب باقة {$planLabel} ({$cycleAr})",
                    'url'   => '/ar/admin?tab=subscriptions',
                    'tag'   => 'sub-request-'.$sub->id,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::warning('push (upgrade request): '.$e->getMessage());
        }

        return response()->json([
            'message' => 'تم استلام طلبك. سيتم تفعيل الباقة بعد التأكيد.',
            'data'    => $sub->load('plan'),
        ], 201);
    }

    /**
     * POST /api/user/subscription/cancel — disables auto-renew.
     */
    public function cancel(Request $request): JsonResponse
    {
        $sub = $this->subs->getCurrent($request->user());
        if ($sub->plan->code === 'free') {
            return response()->json(['message' => 'لا يمكن إلغاء الباقة المجانية.'], 422);
        }

        $this->subs->cancel($sub);

        return response()->json([
            'message' => 'تم إلغاء التجديد التلقائي. ستبقى الباقة فعّالة حتى انتهاء المدة.',
            'data'    => $sub->fresh('plan'),
        ]);
    }

    private function remaining(int|null $limit, int $used): ?int
    {
        if ($limit === null || $limit === -1) return null;
        return max(0, $limit - $used);
    }
}
