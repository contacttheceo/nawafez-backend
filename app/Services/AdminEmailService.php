<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;

/**
 * Centralised emails sent by admin actions (listing approval/rejection,
 * business verification approval/rejection). Uses ResendMailer so it
 * works on Freehostia where SMTP is blocked.
 */
class AdminEmailService
{
    public function __construct(private ResendMailer $mailer)
    {
    }

    /**
     * Gate that decides whether a notification should actually be sent to
     * this user. Skip if:
     *   - the user has no email
     *   - the email is not verified (would bounce or hit Resend reputation)
     *   - the email is a known internal/system placeholder
     *
     * Admin emails bypass this gate (admins are always real verified accounts).
     */
    private function shouldEmailUser(?User $user): bool
    {
        if (!$user || !$user->email) return false;
        // never email accounts that never verified — they're either spam or fake
        if ($user->email_verified_at === null) return false;
        // sanity: skip internal-only domains
        if (str_contains($user->email, '@nwafiz-import.local')) return false;
        return true;
    }

    public function listingApproved(Listing $listing): void
    {
        $user = $listing->user;
        if (!$this->shouldEmailUser($user)) return;

        $title       = $listing->title_ar ?? $listing->title_en ?? '—';
        $frontendUrl = env('FRONTEND_URL', 'https://www.nwafizlogi.com');
        $listingUrl  = "{$frontendUrl}/ar/listings/{$listing->id}";

        $this->mailer->send(
            $user->email,
            "تم نشر إعلانك على نوافذ ✓",
            $this->wrap("
                <h3 style='color:#0a2342;'>مرحباً {$user->name_ar},</h3>
                <p style='color:#444;line-height:1.6;'>
                    تمت الموافقة على إعلانك <strong>«{$title}»</strong> وهو الآن منشور على منصة نوافذ.
                </p>
                <div style='text-align:center;margin:24px 0;'>
                    <a href='{$listingUrl}' style='background:#10b981;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                        عرض الإعلان
                    </a>
                </div>
                <p style='color:#888;font-size:13px;'>شكراً لاستخدامك منصة نوافذ.</p>
            ")
        );
    }

    public function listingRejected(Listing $listing, string $reason): void
    {
        $user = $listing->user;
        if (!$this->shouldEmailUser($user)) return;

        $title       = $listing->title_ar ?? $listing->title_en ?? '—';
        $frontendUrl = env('FRONTEND_URL', 'https://www.nwafizlogi.com');
        $editUrl     = "{$frontendUrl}/ar/listings/{$listing->id}/edit";
        $reasonHtml  = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

        $this->mailer->send(
            $user->email,
            "تعذّر نشر إعلانك على نوافذ",
            $this->wrap("
                <h3 style='color:#0a2342;'>مرحباً {$user->name_ar},</h3>
                <p style='color:#444;line-height:1.6;'>
                    للأسف، لم يتمكّن فريق نوافذ من نشر إعلانك <strong>«{$title}»</strong>.
                </p>
                <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;margin:16px 0;'>
                    <p style='color:#991b1b;font-size:13px;margin:0;'>
                        <strong>سبب الرفض:</strong> {$reasonHtml}
                    </p>
                </div>
                <p style='color:#444;line-height:1.6;'>يمكنك تعديل الإعلان وإعادة نشره:</p>
                <div style='text-align:center;margin:24px 0;'>
                    <a href='{$editUrl}' style='background:#0a2342;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                        تعديل الإعلان
                    </a>
                </div>
            ")
        );
    }

    public function verificationApproved(User $user): void
    {
        if (!$this->shouldEmailUser($user)) return;
        $frontendUrl = env('FRONTEND_URL', 'https://www.nwafizlogi.com');

        $this->mailer->send(
            $user->email,
            "تم توثيق حسابك التجاري على نوافذ ✓",
            $this->wrap("
                <h3 style='color:#0a2342;'>مرحباً {$user->name_ar},</h3>
                <p style='color:#444;line-height:1.6;'>
                    تهانينا — تم قبول طلب توثيق حسابك التجاري على منصة نوافذ. أصبحت الآن مؤسسة موثّقة ✓
                </p>
                <p style='color:#444;line-height:1.6;'>المزايا الجديدة:</p>
                <ul style='color:#444;line-height:1.6;'>
                    <li>شارة \"موثّق\" على إعلاناتك</li>
                    <li>إمكانية تقديم عروض على المناقصات المغلقة</li>
                    <li>أولوية في الظهور بنتائج البحث</li>
                </ul>
                <div style='text-align:center;margin:24px 0;'>
                    <a href='{$frontendUrl}/ar/dashboard' style='background:#10b981;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                        الذهاب للوحة التحكم
                    </a>
                </div>
            ")
        );
    }

    public function verificationRejected(User $user, string $reason): void
    {
        if (!$this->shouldEmailUser($user)) return;
        $frontendUrl = env('FRONTEND_URL', 'https://www.nwafizlogi.com');
        $profileUrl  = "{$frontendUrl}/ar/profile";
        $reasonHtml  = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

        $this->mailer->send(
            $user->email,
            "تعذّر توثيق حسابك التجاري — نوافذ",
            $this->wrap("
                <h3 style='color:#0a2342;'>مرحباً {$user->name_ar},</h3>
                <p style='color:#444;line-height:1.6;'>
                    للأسف، لم يتمكّن فريق نوافذ من قبول طلب توثيق حسابك التجاري.
                </p>
                <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;margin:16px 0;'>
                    <p style='color:#991b1b;font-size:13px;margin:0;'>
                        <strong>السبب:</strong> {$reasonHtml}
                    </p>
                </div>
                <p style='color:#444;line-height:1.6;'>يمكنك إعادة رفع الوثيقة بعد المعالجة:</p>
                <div style='text-align:center;margin:24px 0;'>
                    <a href='{$profileUrl}' style='background:#0a2342;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                        إعادة رفع الوثيقة
                    </a>
                </div>
            ")
        );
    }

    public function accountSuspended(User $user, string $reason): void
    {
        if (!$this->shouldEmailUser($user)) return;
        $reasonHtml = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

        $this->mailer->send(
            $user->email,
            "تم تعليق حسابك على نوافذ",
            $this->wrap("
                <h3 style='color:#0a2342;'>مرحباً {$user->name_ar},</h3>
                <p style='color:#444;line-height:1.6;'>
                    تم تعليق حسابك على منصة نوافذ مؤقتاً.
                </p>
                <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;margin:16px 0;'>
                    <p style='color:#991b1b;font-size:13px;margin:0;'>
                        <strong>السبب:</strong> {$reasonHtml}
                    </p>
                </div>
                <p style='color:#444;line-height:1.6;'>
                    للاستفسار أو الاعتراض، تواصل معنا على
                    <a href='mailto:info@nwafizlogi.com' style='color:#0a2342;'>info@nwafizlogi.com</a>.
                </p>
            ")
        );
    }

    // ─── New: user registration ─────────────────────────────────────────────
    public function welcomeUser(User $user): void
    {
        if (!$user->email) return;
        $name = $user->name_ar ?: $user->name_en ?: 'مستخدم نوافذ';
        $body = "
            <h3 style='color:#0a2342;'>أهلاً وسهلاً {$name} 🎉</h3>
            <p style='color:#444;line-height:1.7;'>
                مرحباً بك في <strong>نوافذ</strong> — منصة اللوجستيك B2B الأولى في السعودية.
            </p>
            <p style='color:#444;line-height:1.7;'>
                حسابك جاهز ويمكنك الآن:
            </p>
            <ul style='color:#444;line-height:1.9;padding-inline-start:20px;'>
                <li>تصفّح آلاف الفرص اللوجستية (أساطيل، عقود، بيع كيانات، توظيف)</li>
                <li>نشر إعلانك مجاناً</li>
                <li>التواصل المباشر مع البائعين والمشترين</li>
            </ul>
            <div style='text-align:center;margin:24px 0;'>
                <a href='https://www.nwafizlogi.com/ar/listings' style='background:#10b981;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                    ابدأ التصفّح
                </a>
            </div>
            <p style='color:#888;font-size:12px;margin-top:24px;'>
                هل لديك سؤال؟ راسلنا على <a href='mailto:support@nwafizlogi.com' style='color:#10b981;'>support@nwafizlogi.com</a>
            </p>";
        $this->mailer->send($user->email, 'أهلاً بك في نوافذ 🎉', $this->wrap($body));
    }

    public function newUserRegisteredToAdmin(User $newUser): void
    {
        $admins = User::where('role', 'admin')
            ->whereNotNull('email')->pluck('email')->all();
        if (empty($admins)) return;

        $name  = htmlspecialchars($newUser->name_ar ?: $newUser->name_en ?: '—');
        $email = htmlspecialchars($newUser->email);
        $phone = htmlspecialchars($newUser->phone ?: '—');
        $role  = htmlspecialchars($newUser->role);

        $body = "
            <h3 style='color:#0a2342;'>🆕 مستخدم جديد سجّل في نوافذ</h3>
            <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>الاسم</td><td style='padding:8px;border-bottom:1px solid #eee;font-weight:bold;'>{$name}</td></tr>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>الإيميل</td><td style='padding:8px;border-bottom:1px solid #eee;'>{$email}</td></tr>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>الجوال</td><td style='padding:8px;border-bottom:1px solid #eee;direction:ltr;text-align:start;'>{$phone}</td></tr>
                <tr><td style='padding:8px;color:#666;'>النوع</td><td style='padding:8px;'>{$role}</td></tr>
            </table>
            <div style='text-align:center;margin:24px 0;'>
                <a href='https://www.nwafizlogi.com/ar/admin' style='background:#0a2342;color:white;padding:10px 24px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:13px;'>
                    افتح لوحة الإدارة
                </a>
            </div>";

        foreach ($admins as $adminEmail) {
            $this->mailer->send($adminEmail, "🆕 مستخدم جديد: {$newUser->name_ar}", $this->wrap($body));
        }
    }

    // ─── New: listing creation ─────────────────────────────────────────────
    public function listingPublishedToOwner(Listing $listing): void
    {
        $user = $listing->user;
        if (!$this->shouldEmailUser($user)) return;

        $title = htmlspecialchars($listing->title_ar ?? $listing->title_en ?? '—');
        $url   = "https://www.nwafizlogi.com/ar/listings/{$listing->id}";

        $body = "
            <h3 style='color:#0a2342;'>تم نشر إعلانك ✓</h3>
            <p style='color:#444;line-height:1.7;'>
                إعلانك <strong>«{$title}»</strong> مباشر الآن على نوافذ ويمكن للمشترين اكتشافه.
            </p>
            <div style='text-align:center;margin:24px 0;'>
                <a href='{$url}' style='background:#10b981;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                    عرض إعلانك
                </a>
            </div>
            <p style='color:#888;font-size:12px;'>
                💡 نصيحة: شارك الرابط في WhatsApp أو تويتر لزيادة المشاهدات بسرعة.
            </p>";
        $this->mailer->send($user->email, "تم نشر إعلانك ✓ — {$title}", $this->wrap($body));
    }

    public function listingPendingReviewToAdmin(Listing $listing): void
    {
        $admins = User::where('role', 'admin')
            ->whereNotNull('email')->pluck('email')->all();
        if (empty($admins)) return;

        $owner   = $listing->user;
        $title   = htmlspecialchars($listing->title_ar ?? $listing->title_en ?? '—');
        $section = htmlspecialchars($listing->section);
        $ownerName = htmlspecialchars($owner->name_ar ?: $owner->name_en ?: '—');
        $url     = "https://www.nwafizlogi.com/ar/admin";

        $body = "
            <h3 style='color:#0a2342;'>📋 إعلان جديد في انتظار المراجعة</h3>
            <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>العنوان</td><td style='padding:8px;border-bottom:1px solid #eee;font-weight:bold;'>{$title}</td></tr>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>القسم</td><td style='padding:8px;border-bottom:1px solid #eee;'>{$section}</td></tr>
                <tr><td style='padding:8px;color:#666;'>المُعلِن</td><td style='padding:8px;'>{$ownerName}</td></tr>
            </table>
            <div style='text-align:center;margin:24px 0;'>
                <a href='{$url}' style='background:#0a2342;color:white;padding:10px 24px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:13px;'>
                    مراجعة الإعلان
                </a>
            </div>";

        foreach ($admins as $adminEmail) {
            $this->mailer->send($adminEmail, "📋 إعلان جديد للمراجعة — {$title}", $this->wrap($body));
        }
    }

    // ─── New: comment on listing ───────────────────────────────────────────
    public function newCommentToListingOwner(Listing $listing, $comment, User $commentAuthor): void
    {
        $owner = $listing->user;
        if (!$this->shouldEmailUser($owner) || $owner->id === $commentAuthor->id) return;

        $title   = htmlspecialchars($listing->title_ar ?? $listing->title_en ?? '—');
        $author  = htmlspecialchars($commentAuthor->name_ar ?: $commentAuthor->name_en ?: 'مستخدم');
        $body_text = htmlspecialchars(mb_substr($comment->body ?? '', 0, 300));
        $url     = "https://www.nwafizlogi.com/ar/listings/{$listing->id}#comment-{$comment->id}";

        $body = "
            <h3 style='color:#0a2342;'>💬 تعليق جديد على إعلانك</h3>
            <p style='color:#666;font-size:13px;'>
                <strong>{$author}</strong> علّق على إعلانك <strong>«{$title}»</strong>:
            </p>
            <blockquote style='background:white;border-inline-start:4px solid #10b981;padding:14px 16px;margin:16px 0;border-radius:6px;color:#444;line-height:1.7;'>
                {$body_text}
            </blockquote>
            <div style='text-align:center;margin:24px 0;'>
                <a href='{$url}' style='background:#10b981;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                    عرض ورد
                </a>
            </div>";

        $this->mailer->send($owner->email, "💬 تعليق جديد على «{$title}»", $this->wrap($body));
    }

    /** Reusable HTML wrapper */
    private function wrap(string $body): string
    {
        return "
        <div dir='rtl' style='font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:32px;background:#f9f9f9;border-radius:12px;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <div style='display:inline-block;background:#0a2342;color:white;width:48px;height:48px;border-radius:10px;font-size:24px;font-weight:900;line-height:48px;'>ن</div>
                <h2 style='color:#0a2342;margin:12px 0 4px;'>نوافذ</h2>
            </div>
            {$body}
            <hr style='border:none;border-top:1px solid #eee;margin:24px 0;'>
            <p style='color:#aaa;font-size:11px;text-align:center;'>
                نوافذ — منصة اللوجستيك B2B في السعودية | <a href='https://www.nwafizlogi.com' style='color:#aaa;'>www.nwafizlogi.com</a>
            </p>
        </div>";
    }
}
