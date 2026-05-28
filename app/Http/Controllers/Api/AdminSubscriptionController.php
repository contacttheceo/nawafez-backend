<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only endpoints for managing user subscriptions and pricing plans.
 *
 * Routes (all under /api/admin):
 *   GET    /subscriptions              — paginated list with filters
 *   GET    /subscriptions/pending      — only pending upgrade requests
 *   POST   /users/{id}/grant-plan      — grant a plan to a user
 *   PATCH  /subscriptions/{id}         — extend / change cycle / cancel
 *   GET    /plans                      — list plans with stats
 *   PATCH  /plans/{id}                 — edit price/features without deploy
 */
class AdminSubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subs)
    {
    }

    // GET /api/admin/subscriptions?status=active&plan=basic
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::with(['user:id,name_ar,name_en,email,phone', 'plan']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('plan')) {
            $query->whereHas('plan', fn ($q) => $q->where('code', $request->plan));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        return response()->json($query->orderByDesc('id')->paginate(20));
    }

    // GET /api/admin/subscriptions/pending — users awaiting upgrade confirmation
    public function pending(Request $request): JsonResponse
    {
        $rows = Subscription::with(['user:id,name_ar,name_en,email,phone', 'plan'])
            ->where('status', 'pending')
            ->where('source', 'upgrade_request')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    // POST /api/admin/users/{id}/grant-plan
    public function grantPlan(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'plan_code'     => ['required', 'string', 'exists:plans,code'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'note'          => ['nullable', 'string', 'max:300'],
        ]);

        $user = User::findOrFail($id);
        $plan = Plan::where('code', $request->plan_code)->firstOrFail();

        $sub = $this->subs->grant(
            $user,
            $plan,
            $request->billing_cycle,
            $request->user()->id,
            'admin_grant'
        );

        AuditLogger::log(
            $request->user(),
            'subscription.grant',
            'user',
            $user->id,
            [
                'plan'  => $plan->code,
                'cycle' => $request->billing_cycle,
                'note'  => $request->note,
            ],
            $request
        );

        return response()->json([
            'message' => 'تم تفعيل الباقة للمستخدم.',
            'data'    => $sub->load('plan'),
        ]);
    }

    // PATCH /api/admin/subscriptions/{id}  — extend / cancel / change plan
    public function update(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'action'      => ['required', 'in:extend,cancel,activate'],
            'extend_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $sub = Subscription::findOrFail($id);

        match ($request->action) {
            'extend' => $sub->update([
                'expires_at' => ($sub->expires_at ?? now())->copy()->addDays($request->extend_days ?? 30),
                'status'     => 'active',
            ]),
            'cancel' => $this->subs->cancel($sub),
            'activate' => $sub->update([
                'status'     => 'active',
                'started_at' => $sub->started_at ?? now(),
                'expires_at' => $sub->expires_at  ?? now()->addMonths(
                    $sub->billing_cycle === 'yearly' ? 12 : 1
                ),
            ]),
        };

        AuditLogger::log(
            $request->user(),
            'subscription.'.$request->action,
            'subscription',
            $sub->id,
            ['plan' => $sub->plan->code ?? null],
            $request
        );

        return response()->json([
            'message' => 'تم تنفيذ الإجراء.',
            'data'    => $sub->fresh('plan'),
        ]);
    }

    // GET /api/admin/plans  — list with subscriber counts for analytics
    public function plansIndex(): JsonResponse
    {
        $plans = Plan::withCount(['subscriptions' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('display_order')
            ->get();
        return response()->json(['data' => $plans]);
    }

    // PATCH /api/admin/plans/{id} — edit pricing & features without deploy
    public function plansUpdate(Request $request, int $id): JsonResponse
    {
        $this->validate($request, [
            'price_monthly' => ['sometimes', 'integer', 'min:0'],
            'price_yearly'  => ['sometimes', 'integer', 'min:0'],
            'features'      => ['sometimes', 'array'],
            'is_active'     => ['sometimes', 'boolean'],
            'name_ar'       => ['sometimes', 'string', 'max:100'],
            'name_en'       => ['sometimes', 'string', 'max:100'],
            'tagline_ar'    => ['sometimes', 'nullable', 'string', 'max:200'],
            'tagline_en'    => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $plan = Plan::findOrFail($id);
        $plan->update($request->only([
            'price_monthly', 'price_yearly', 'features',
            'is_active', 'name_ar', 'name_en', 'tagline_ar', 'tagline_en',
        ]));

        AuditLogger::log(
            $request->user(),
            'plan.update',
            'plan',
            $plan->id,
            ['plan_code' => $plan->code],
            $request
        );

        return response()->json([
            'message' => 'تم تحديث الباقة.',
            'data'    => $plan->fresh(),
        ]);
    }
}
