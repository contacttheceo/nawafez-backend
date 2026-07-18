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
                <h3 style='color:#526483;'>مرحباً {$user->name_ar},</h3>
                <p style='color:#444;line-height:1.6;'>
                    تمت الموافقة على إعلانك <strong>«{$title}»</strong> وهو الآن منشور على منصة نوافذ.
                </p>
                <div style='text-align:center;margin:24px 0;'>
                    <a href='{$listingUrl}' style='background:#0D9B6C;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
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
                <h3 style='color:#526483;'>مرحباً {$user->name_ar},</h3>
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
                    <a href='{$editUrl}' style='background:#526483;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
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
                <h3 style='color:#526483;'>مرحباً {$user->name_ar},</h3>
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
                    <a href='{$frontendUrl}/ar/dashboard' style='background:#0D9B6C;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
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
                <h3 style='color:#526483;'>مرحباً {$user->name_ar},</h3>
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
                    <a href='{$profileUrl}' style='background:#526483;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
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
                <h3 style='color:#526483;'>مرحباً {$user->name_ar},</h3>
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
                    <a href='mailto:info@nwafizlogi.com' style='color:#526483;'>info@nwafizlogi.com</a>.
                </p>
            ")
        );
    }

    /**
     * Sent when an admin "activates" an account from the admin panel.
     * Different from the simple welcome email — this is the full marketing
     * pitch + features showcase + referral incentive. Designed to convert
     * a freshly-verified user into an actively-posting user.
     *
     * The "ceremonial" version, fired by admin action, not auto-on-register.
     */
    public function accountActivated(User $user): void
    {
        // No verification gate here — admin explicitly chose to send this.
        if (!$user || !$user->email) return;

        $name        = htmlspecialchars($user->name_ar ?: $user->name_en ?: 'مستخدم نوافذ', ENT_QUOTES, 'UTF-8');
        $userId      = $user->id;
        $referralUrl = "https://www.nwafizlogi.com/ar/auth/register?ref={$userId}";
        $browseUrl   = "https://www.nwafizlogi.com/ar/listings";
        $postUrl     = "https://www.nwafizlogi.com/ar/listings/create";

        $body = "
            <!-- Big welcome -->
            <div style='text-align:center;margin-bottom:24px;'>
                <div style='font-size:48px;line-height:1;margin-bottom:8px;'>🎉</div>
                <h2 style='color:#526483;font-size:24px;margin:0 0 8px;'>تم تفعيل حسابك يا {$name}!</h2>
                <p style='color:#666;font-size:14px;margin:0;line-height:1.6;'>
                    أهلاً بك في عائلة <strong>نوافذ</strong> — منصة اللوجستيك B2B الأولى في السعودية.
                </p>
            </div>

            <!-- Primary CTA -->
            <div style='text-align:center;margin:28px 0;'>
                <a href='{$postUrl}' style='background:#0D9B6C;color:white;padding:14px 36px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:15px;display:inline-block;box-shadow:0 4px 12px rgba(16,185,129,0.25);'>
                    🚀 أنشر إعلانك الأول
                </a>
            </div>

            <p style='color:#666;font-size:13px;text-align:center;margin:-12px 0 20px;'>
                أو <a href='{$browseUrl}' style='color:#0D9B6C;text-decoration:none;font-weight:bold;'>تصفّح الإعلانات الموجودة</a>
            </p>

            <hr style='border:none;border-top:1px solid #eee;margin:24px 0;'>

            <!-- Features showcase -->
            <h3 style='color:#526483;font-size:18px;margin-bottom:16px;'>✨ كل هذا متاح لك مجاناً الآن:</h3>

            <table style='width:100%;border-collapse:collapse;margin-bottom:16px;'>
                <tr>
                    <td style='padding:12px 8px;vertical-align:top;width:32px;'>
                        <div style='font-size:22px;'>🚛</div>
                    </td>
                    <td style='padding:12px 8px;'>
                        <p style='margin:0 0 4px;color:#526483;font-weight:bold;font-size:14px;'>تصفّح آلاف الفرص اللوجستية</p>
                        <p style='margin:0;color:#666;font-size:13px;line-height:1.6;'>
                            أساطيل، عقود تشغيلية، بيع كيانات (M&A)، وظائف، ومنتدى استشارات — كله في مكان واحد.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style='padding:12px 8px;vertical-align:top;'><div style='font-size:22px;'>📢</div></td>
                    <td style='padding:12px 8px;'>
                        <p style='margin:0 0 4px;color:#526483;font-weight:bold;font-size:14px;'>انشر إعلاناتك مجاناً</p>
                        <p style='margin:0;color:#666;font-size:13px;line-height:1.6;'>
                            صور، تفاصيل، سعر، فلاتر ذكية — يصل لمشترين جدّيين في السعودية.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style='padding:12px 8px;vertical-align:top;'><div style='font-size:22px;'>💬</div></td>
                    <td style='padding:12px 8px;'>
                        <p style='margin:0 0 4px;color:#526483;font-weight:bold;font-size:14px;'>تواصل مباشر بدون كشف رقمك</p>
                        <p style='margin:0;color:#666;font-size:13px;line-height:1.6;'>
                            رسائل داخلية آمنة، أو تواصل بالواتساب عند الجدّية فقط.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style='padding:12px 8px;vertical-align:top;'><div style='font-size:22px;'>🤖</div></td>
                    <td style='padding:12px 8px;'>
                        <p style='margin:0 0 4px;color:#526483;font-weight:bold;font-size:14px;'>ذكاء اصطناعي يساعدك</p>
                        <p style='margin:0;color:#666;font-size:13px;line-height:1.6;'>
                            AI يكتب إعلانك من وصف بسيط، ويحلّل العقود قبل التوقيع.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style='padding:12px 8px;vertical-align:top;'><div style='font-size:22px;'>✓</div></td>
                    <td style='padding:12px 8px;'>
                        <p style='margin:0 0 4px;color:#526483;font-weight:bold;font-size:14px;'>وثّق نشاطك التجاري</p>
                        <p style='margin:0;color:#666;font-size:13px;line-height:1.6;'>
                            ارفع سجلك التجاري واحصل على شارة <strong>'موثّق'</strong> — تزيد ثقة المشترين × 3.
                        </p>
                    </td>
                </tr>
            </table>

            <!-- Referral program (the big motivator) -->
            <div style='background:linear-gradient(135deg,#0D9B6C 0%,#0d9b6c 100%);border-radius:12px;padding:24px;margin:24px 0;color:white;'>
                <h3 style='margin:0 0 10px;font-size:18px;'>🌱 ادعُ زملاءك واكسب مكافآت</h3>
                <p style='margin:0 0 14px;font-size:13px;line-height:1.7;opacity:0.95;'>
                    شارك رابطك الخاص — كل صديق ينضم، تكسب:
                </p>
                <ul style='margin:0 0 16px;padding-inline-start:18px;font-size:13px;line-height:1.9;'>
                    <li>🎖️ شارة <strong>'السفير'</strong> دائمة بجانب اسمك</li>
                    <li>📈 إعلانك في <strong>قسم 'المميّز'</strong> لمدة 7 أيام</li>
                    <li>🎁 <strong>شهر Pro مجاناً</strong> عند تفعيل التسعير (قيمته 299 ر.س)</li>
                </ul>
                <p style='margin:0 0 8px;font-size:12px;opacity:0.85;'>رابط دعوتك الخاص:</p>
                <div style='background:rgba(255,255,255,0.15);border-radius:8px;padding:10px 12px;font-family:monospace;font-size:11px;word-break:break-all;direction:ltr;text-align:left;'>
                    {$referralUrl}
                </div>
            </div>

            <!-- Quick tips -->
            <div style='background:#fef3c7;border-radius:8px;padding:16px;margin:20px 0;border-inline-start:4px solid #f59e0b;'>
                <p style='margin:0 0 6px;color:#92400e;font-weight:bold;font-size:13px;'>💡 نصيحة سريعة</p>
                <p style='margin:0;color:#78350f;font-size:12px;line-height:1.7;'>
                    الإعلانات بصور حقيقية تحصل على <strong>تواصل أكثر بـ 5 مرّات</strong> من الإعلانات بدون صور.
                    حاول تنشر إعلانك الأول بـ 2-3 صور واضحة.
                </p>
            </div>

            <!-- Secondary CTAs -->
            <div style='text-align:center;margin:24px 0 16px;'>
                <a href='{$postUrl}' style='background:#526483;color:white;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:13px;display:inline-block;margin:4px;'>
                    📢 أنشر إعلاناً
                </a>
                <a href='{$browseUrl}' style='background:white;color:#526483;border:1px solid #e5e7eb;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:13px;display:inline-block;margin:4px;'>
                    🔍 تصفّح السوق
                </a>
            </div>

            <p style='color:#888;font-size:12px;text-align:center;margin-top:24px;line-height:1.7;'>
                لو احتجت أي مساعدة، نحن هنا:<br>
                <a href='mailto:support@nwafizlogi.com' style='color:#0D9B6C;text-decoration:none;font-weight:bold;'>support@nwafizlogi.com</a>
            </p>
        ";

        $this->mailer->send($user->email, '🎉 مرحباً بك في نوافذ — حسابك مفعّل!', $this->wrap($body));
    }

    // ─── New: user registration ─────────────────────────────────────────────
    public function welcomeUser(User $user): void
    {
        if (!$user->email) return;
        $name = $user->name_ar ?: $user->name_en ?: 'مستخدم نوافذ';
        $body = "
            <h3 style='color:#526483;'>أهلاً وسهلاً {$name} 🎉</h3>
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
                <a href='https://www.nwafizlogi.com/ar/listings' style='background:#0D9B6C;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                    ابدأ التصفّح
                </a>
            </div>
            <p style='color:#888;font-size:12px;margin-top:24px;'>
                هل لديك سؤال؟ راسلنا على <a href='mailto:support@nwafizlogi.com' style='color:#0D9B6C;'>support@nwafizlogi.com</a>
            </p>";
        $this->mailer->send($user->email, 'أهلاً بك في نوافذ 🎉', $this->wrap($body));
    }

    // ─── Onboarding drip (day 2, 5, 9, 14) ─────────────────────────────────
    // Each method checks shouldEmailUser() so unverified accounts get skipped.

    /** Day 2 — nudge for users who haven't posted a listing yet. */
    public function onboardingNudge(User $user): void
    {
        if (!$this->shouldEmailUser($user)) return;
        $name = htmlspecialchars($user->name_ar ?: $user->name_en ?: 'مستخدم نوافذ');
        $body = "
            <h3 style='color:#526483;'>🚛 خمس دقائق = إعلانك الأول على نوافذ</h3>
            <p style='color:#444;line-height:1.8;'>
                أهلاً {$name}،<br>
                لاحظنا أنك لم تنشر إعلانك الأول بعد. ربما أنت تستكشف، وهذا طبيعي تماماً.
            </p>
            <p style='color:#444;line-height:1.8;'>هل عندك:</p>
            <ul style='color:#444;line-height:1.9;'>
                <li>شاحنة عاطلة في الجراج؟</li>
                <li>خدمة نقل تقدمها؟</li>
                <li>وظيفة شاغرة؟</li>
                <li>سؤال تقني أو قانوني عن قطاع اللوجستيك؟</li>
            </ul>
            <p style='color:#444;line-height:1.8;'><strong>أيها يصلح كإعلانك الأول.</strong> النشر مجاني تماماً خلال فترة الإطلاق.</p>
            <div style='text-align:center;margin:26px 0;'>
                <a href='https://www.nwafizlogi.com/ar/listings/create' style='background:#0D9B6C;color:white;padding:13px 30px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                    + أضف إعلانك الآن
                </a>
            </div>";
        $this->mailer->send($user->email, '🚛 خمس دقائق = إعلانك الأول على نوافذ', $this->wrap($body));
    }

    /** Day 5 — educational: send the article on selling a truck. */
    public function onboardingEducational(User $user): void
    {
        if (!$this->shouldEmailUser($user)) return;
        $body = "
            <h3 style='color:#526483;'>📚 دليل: كيف تبيع شاحنتك في السعودية</h3>
            <p style='color:#444;line-height:1.8;'>
                نشرنا دليلاً جديداً يستحق وقتك — 7 دقائق قراءة، يغطي:
            </p>
            <ul style='color:#444;line-height:1.9;'>
                <li>كيف تحدّد سعر بيع واقعي</li>
                <li>المستندات التي تحتاجها قبل الإعلان</li>
                <li>تصوير احترافي = ضعف الاستفسارات</li>
                <li>التفاوض — 3 قواعد ذهبية</li>
            </ul>
            <div style='text-align:center;margin:24px 0;'>
                <a href='https://www.nwafizlogi.com/ar/articles/how-to-sell-truck-in-saudi-arabia' style='background:#526483;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                    اقرأ الدليل ←
                </a>
            </div>
            <p style='color:#666;font-size:13px;text-align:center;'>
                لدينا 5 دلائل مماثلة:
                <a href='https://www.nwafizlogi.com/ar/articles' style='color:#0D9B6C;'>تصفّح المكتبة</a>
            </p>";
        $this->mailer->send($user->email, '📚 دليل جديد: بيع شاحنتك في السعودية', $this->wrap($body));
    }

    /** Day 9 — social proof + scarcity for the free-launch period. */
    public function onboardingSocialProof(User $user): void
    {
        if (!$this->shouldEmailUser($user)) return;
        $listingCount = \App\Models\Listing::where('status', 'active')->count();
        $userCount    = User::count();
        $body = "
            <h3 style='color:#526483;'>📊 {$listingCount} إعلان نشط — هل إعلانك أحدها؟</h3>
            <p style='color:#444;line-height:1.8;'>
                على نوافذ الآن <strong>{$listingCount} إعلان نشط</strong> من
                <strong>{$userCount}+ مستخدم</strong> في 15 مدينة سعودية.
            </p>
            <p style='color:#444;line-height:1.8;'>
                نوافذ مجاناً 100% خلال فترة الإطلاق. لاحقاً سنطلق باقات مدفوعة
                (Pro: إعلانات unlimited + featured + تحليلات).
            </p>
            <p style='color:#526483;line-height:1.8;background:#fef3c7;padding:14px;border-radius:8px;'>
                <strong>🎁 كل من سجّل خلال فترة الإطلاق</strong> يحصل على
                <strong>Pro مجاناً لـ 6 أشهر</strong> عند إطلاق الباقات.
            </p>
            <div style='text-align:center;margin:24px 0;'>
                <a href='https://www.nwafizlogi.com/dashboard' style='background:#0D9B6C;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                    افتح لوحتي
                </a>
            </div>";
        $this->mailer->send($user->email, "📊 {$listingCount} إعلان نشط — انضم الآن", $this->wrap($body));
    }

    /** Newsletter signup welcome — no user account required, just an email. */
    public function newsletterWelcome(string $email, string $locale = 'ar'): void
    {
        $isAr = $locale === 'ar';
        $body = $isAr
            ? "
                <h3 style='color:#526483;'>أهلاً بك في نشرة نوافذ 📬</h3>
                <p style='color:#444;line-height:1.8;'>
                    تم اشتراكك بنجاح. سنرسل لك:
                </p>
                <ul style='color:#444;line-height:1.9;'>
                    <li>أبرز الإعلانات الجديدة كل أسبوع</li>
                    <li>تحديثات قطاع النقل واللوجستيك في السعودية</li>
                    <li>دلائل عملية ومحتوى تعليمي</li>
                </ul>
                <p style='color:#444;line-height:1.8;'>
                    لا spam، لا إعلانات خارجية. فقط ما يستحق وقتك.
                </p>
                <div style='text-align:center;margin:24px 0;'>
                    <a href='https://www.nwafizlogi.com/ar/listings'
                       style='background:#0D9B6C;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                        تصفّح المنصة الآن
                    </a>
                </div>"
            : "
                <h3 style='color:#526483;'>Welcome to the Nwafiz Newsletter 📬</h3>
                <p style='color:#444;line-height:1.8;'>
                    You're subscribed. We'll send you:
                </p>
                <ul style='color:#444;line-height:1.9;'>
                    <li>Top new listings every week</li>
                    <li>Saudi transport and logistics sector updates</li>
                    <li>Practical guides and educational content</li>
                </ul>
                <p style='color:#444;line-height:1.8;'>
                    No spam, no third-party ads. Only what's worth your time.
                </p>
                <div style='text-align:center;margin:24px 0;'>
                    <a href='https://www.nwafizlogi.com/en/listings'
                       style='background:#0D9B6C;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                        Browse the platform
                    </a>
                </div>";

        $subject = $isAr ? 'أهلاً بك في نشرة نوافذ 📬' : 'Welcome to the Nwafiz Newsletter 📬';
        $this->mailer->send($email, $subject, $this->wrap($body));
    }

    /** Day 14 — direct ask for feedback. Highest reply rate. */
    public function onboardingFeedback(User $user): void
    {
        if (!$this->shouldEmailUser($user)) return;
        $name = htmlspecialchars($user->name_ar ?: $user->name_en ?: 'صديقنا');
        $body = "
            <h3 style='color:#526483;'>سؤال مباشر — هل نوافذ تخدمك؟</h3>
            <p style='color:#444;line-height:1.8;'>
                أهلاً {$name}،<br>
                مرّ أسبوعان على تسجيلك في نوافذ. وقت كافٍ لتكوين انطباع.
            </p>
            <p style='color:#444;line-height:1.8;'><strong>سؤال واحد فقط</strong> — كيف تقيّم تجربتك حتى الآن؟</p>
            <ul style='color:#444;line-height:1.9;list-style:none;padding-inline-start:0;'>
                <li>(أ) ممتازة — أستخدمها بانتظام</li>
                <li>(ب) جيدة — أحتاج تعريف أكثر بالميزات</li>
                <li>(ج) متوسطة — لم أستفد كما توقّعت</li>
                <li>(د) سيئة — لم أجد ما أبحث عنه</li>
            </ul>
            <p style='color:#444;line-height:1.8;'>
                ردّ بحرف واحد على هذا الإيميل وأنا أتابع من هناك. كل ردّ صادق يساعدنا
                نبني منصة أفضل.
            </p>
            <p style='color:#888;font-size:12px;margin-top:18px;'>
                شكراً على وقتك،<br>
                فريق نوافذ
            </p>";
        $this->mailer->send($user->email, 'سؤال واحد فقط — كيف تجربتك مع نوافذ؟', $this->wrap($body));
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
            <h3 style='color:#526483;'>🆕 مستخدم جديد سجّل في نوافذ</h3>
            <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>الاسم</td><td style='padding:8px;border-bottom:1px solid #eee;font-weight:bold;'>{$name}</td></tr>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>الإيميل</td><td style='padding:8px;border-bottom:1px solid #eee;'>{$email}</td></tr>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>الجوال</td><td style='padding:8px;border-bottom:1px solid #eee;direction:ltr;text-align:start;'>{$phone}</td></tr>
                <tr><td style='padding:8px;color:#666;'>النوع</td><td style='padding:8px;'>{$role}</td></tr>
            </table>
            <div style='text-align:center;margin:24px 0;'>
                <a href='https://www.nwafizlogi.com/ar/admin' style='background:#526483;color:white;padding:10px 24px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:13px;'>
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
            <h3 style='color:#526483;'>تم نشر إعلانك ✓</h3>
            <p style='color:#444;line-height:1.7;'>
                إعلانك <strong>«{$title}»</strong> مباشر الآن على نوافذ ويمكن للمشترين اكتشافه.
            </p>
            <div style='text-align:center;margin:24px 0;'>
                <a href='{$url}' style='background:#0D9B6C;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
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
            <h3 style='color:#526483;'>📋 إعلان جديد في انتظار المراجعة</h3>
            <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>العنوان</td><td style='padding:8px;border-bottom:1px solid #eee;font-weight:bold;'>{$title}</td></tr>
                <tr><td style='padding:8px;border-bottom:1px solid #eee;color:#666;'>القسم</td><td style='padding:8px;border-bottom:1px solid #eee;'>{$section}</td></tr>
                <tr><td style='padding:8px;color:#666;'>المُعلِن</td><td style='padding:8px;'>{$ownerName}</td></tr>
            </table>
            <div style='text-align:center;margin:24px 0;'>
                <a href='{$url}' style='background:#526483;color:white;padding:10px 24px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:13px;'>
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
            <h3 style='color:#526483;'>💬 تعليق جديد على إعلانك</h3>
            <p style='color:#666;font-size:13px;'>
                <strong>{$author}</strong> علّق على إعلانك <strong>«{$title}»</strong>:
            </p>
            <blockquote style='background:white;border-inline-start:4px solid #0D9B6C;padding:14px 16px;margin:16px 0;border-radius:6px;color:#444;line-height:1.7;'>
                {$body_text}
            </blockquote>
            <div style='text-align:center;margin:24px 0;'>
                <a href='{$url}' style='background:#0D9B6C;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:bold;'>
                    عرض ورد
                </a>
            </div>";

        $this->mailer->send($owner->email, "💬 تعليق جديد على «{$title}»", $this->wrap($body));
    }

    /**
     * Daily activity digest sent to all admins at 08:00 Asia/Riyadh.
     *
     * $stats shape:
     *   - date_label: string (e.g. "الإثنين 8 يونيو 2026")
     *   - new_users: int
     *   - new_listings: int
     *   - pending_review: int
     *   - listing_views_24h: int      (sum of views_count delta — approximate)
     *   - total_users: int
     *   - total_active_listings: int
     *   - section_breakdown: array<string, int>  (e.g. ['fleet' => 3, 'jobs' => 1])
     *   - top_listings: Collection<Listing>      (top 5 by views_count, with id/title)
     */
    public function adminDailyDigest(array $stats): void
    {
        $admins = User::where('role', 'admin')
            ->whereNotNull('email')->pluck('email')->all();
        if (empty($admins)) return;

        $date            = htmlspecialchars($stats['date_label']);
        $newUsers        = (int) ($stats['new_users'] ?? 0);
        $newListings     = (int) ($stats['new_listings'] ?? 0);
        $pendingReview   = (int) ($stats['pending_review'] ?? 0);
        $totalUsers      = (int) ($stats['total_users'] ?? 0);
        $totalActive     = (int) ($stats['total_active_listings'] ?? 0);
        $listingViews    = (int) ($stats['listing_views_24h'] ?? 0);
        $sectionLabels   = [
            'ma' => 'استحواذ ودمج', 'fleet' => 'أسطول', 'contracts' => 'عقود',
            'jobs' => 'توظيف', 'forum' => 'منتدى',
        ];

        // Section breakdown rows
        $sectionRows = '';
        foreach (($stats['section_breakdown'] ?? []) as $sec => $count) {
            $label = htmlspecialchars($sectionLabels[$sec] ?? $sec);
            $sectionRows .= "<tr>
                <td style='padding:6px 8px;color:#555;'>{$label}</td>
                <td style='padding:6px 8px;text-align:start;font-weight:bold;color:#526483;'>{$count}</td>
            </tr>";
        }
        if ($sectionRows === '') {
            $sectionRows = "<tr><td colspan='2' style='padding:8px;color:#999;text-align:center;'>لا توجد إعلانات جديدة اليوم</td></tr>";
        }

        // Top-viewed listings rows
        $topRows = '';
        foreach (($stats['top_listings'] ?? []) as $l) {
            $title = htmlspecialchars(mb_substr($l->title_ar ?? $l->title_en ?? '—', 0, 60));
            $views = (int) ($l->views_count ?? 0);
            $url   = "https://www.nwafizlogi.com/ar/listings/{$l->id}";
            $topRows .= "<tr>
                <td style='padding:6px 8px;'><a href='{$url}' style='color:#526483;text-decoration:none;'>{$title}</a></td>
                <td style='padding:6px 8px;text-align:start;color:#0D9B6C;font-weight:bold;'>{$views} 👁</td>
            </tr>";
        }
        if ($topRows === '') {
            $topRows = "<tr><td colspan='2' style='padding:8px;color:#999;text-align:center;'>لا توجد بيانات بعد</td></tr>";
        }

        $body = "
            <h2 style='color:#526483;margin:0 0 4px;'>📊 ملخص نشاط نوافذ</h2>
            <p style='color:#888;font-size:13px;margin:0 0 24px;'>{$date}</p>

            <!-- KPI cards -->
            <table style='width:100%;border-collapse:separate;border-spacing:8px 0;margin-bottom:20px;'>
                <tr>
                    <td style='background:#0D9B6C;color:white;padding:16px;border-radius:10px;text-align:center;width:33%;'>
                        <div style='font-size:11px;opacity:0.85;'>مستخدمون جدد</div>
                        <div style='font-size:26px;font-weight:bold;line-height:1.1;margin-top:6px;'>{$newUsers}</div>
                    </td>
                    <td style='background:#526483;color:white;padding:16px;border-radius:10px;text-align:center;width:33%;'>
                        <div style='font-size:11px;opacity:0.85;'>إعلانات جديدة</div>
                        <div style='font-size:26px;font-weight:bold;line-height:1.1;margin-top:6px;'>{$newListings}</div>
                    </td>
                    <td style='background:#f59e0b;color:white;padding:16px;border-radius:10px;text-align:center;width:33%;'>
                        <div style='font-size:11px;opacity:0.85;'>قيد المراجعة</div>
                        <div style='font-size:26px;font-weight:bold;line-height:1.1;margin-top:6px;'>{$pendingReview}</div>
                    </td>
                </tr>
            </table>

            <!-- Totals strip -->
            <div style='background:white;padding:14px 16px;border-radius:10px;margin-bottom:20px;border:1px solid #eee;'>
                <table style='width:100%;font-size:13px;'>
                    <tr>
                        <td style='color:#666;'>إجمالي المستخدمين</td>
                        <td style='text-align:start;font-weight:bold;color:#526483;'>{$totalUsers}</td>
                    </tr>
                    <tr>
                        <td style='color:#666;padding-top:6px;'>إعلانات نشطة</td>
                        <td style='text-align:start;font-weight:bold;color:#526483;padding-top:6px;'>{$totalActive}</td>
                    </tr>
                    <tr>
                        <td style='color:#666;padding-top:6px;'>مشاهدات الإعلانات (24س)</td>
                        <td style='text-align:start;font-weight:bold;color:#526483;padding-top:6px;'>{$listingViews}</td>
                    </tr>
                </table>
            </div>

            <!-- Section breakdown -->
            <h3 style='color:#526483;font-size:14px;margin:24px 0 8px;'>📂 الإعلانات الجديدة حسب القسم</h3>
            <table style='width:100%;background:white;border:1px solid #eee;border-radius:10px;overflow:hidden;font-size:13px;'>
                {$sectionRows}
            </table>

            <!-- Top viewed listings -->
            <h3 style='color:#526483;font-size:14px;margin:24px 0 8px;'>🔥 أعلى الإعلانات مشاهدةً</h3>
            <table style='width:100%;background:white;border:1px solid #eee;border-radius:10px;overflow:hidden;font-size:13px;'>
                {$topRows}
            </table>

            <div style='text-align:center;margin:28px 0 0;'>
                <a href='https://www.nwafizlogi.com/ar/admin'
                   style='background:#526483;color:white;padding:11px 28px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:13px;'>
                    لوحة الإدارة
                </a>
            </div>";

        $subject = "📊 تقرير نوافذ اليومي — {$date}";
        foreach ($admins as $adminEmail) {
            $this->mailer->send($adminEmail, $subject, $this->wrap($body));
        }
    }

    /** Reusable HTML wrapper — uses the actual brand logo image. */
    private function wrap(string $body): string
    {
        $logoUrl = self::LOGO_URL;
        return "
        <div dir='rtl' style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:32px;background:#f9f9f9;border-radius:12px;'>
            <div style='text-align:center;margin-bottom:28px;padding:18px;background:white;border-radius:10px;'>
                <img src='{$logoUrl}'
                     alt='نوافذ — Nwafiz Logistics'
                     width='140'
                     style='max-width:140px;height:auto;display:inline-block;border:0;' />
            </div>
            {$body}
            <hr style='border:none;border-top:1px solid #eee;margin:28px 0 16px;'>
            <p style='color:#aaa;font-size:11px;text-align:center;line-height:1.6;'>
                <strong>نوافذ</strong> — منصة اللوجستيك B2B في السعودية<br>
                <a href='https://www.nwafizlogi.com' style='color:#0D9B6C;text-decoration:none;'>www.nwafizlogi.com</a>
                &nbsp;·&nbsp;
                <a href='mailto:support@nwafizlogi.com' style='color:#0D9B6C;text-decoration:none;'>support@nwafizlogi.com</a>
            </p>
        </div>";
    }

    /** Public so it can be reused by other email-producing services. */
    public const LOGO_URL = 'https://www.nwafizlogi.com/logo.png';
}
