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

    public function listingApproved(Listing $listing): void
    {
        $user = $listing->user;
        if (!$user || !$user->email) return;

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
        if (!$user || !$user->email) return;

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
        if (!$user->email) return;
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
        if (!$user->email) return;
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
        if (!$user->email) return;
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
