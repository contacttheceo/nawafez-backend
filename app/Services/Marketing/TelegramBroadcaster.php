<?php

namespace App\Services\Marketing;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts approved listings to a public Telegram channel.
 *
 * Config (env):
 *   TELEGRAM_BOT_TOKEN   — bot token from @BotFather
 *   TELEGRAM_CHANNEL_ID  — channel handle (e.g. "@nwafiz_logi") or numeric id
 *
 * Trigger: called from ListingController after admin approval.
 * No-op if either env var is missing — safe to deploy before the bot
 * is created.
 */
class TelegramBroadcaster
{
    private ?string $token;
    private ?string $channel;

    public function __construct()
    {
        $this->token   = env('TELEGRAM_BOT_TOKEN');
        $this->channel = env('TELEGRAM_CHANNEL_ID');
    }

    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->channel);
    }

    /** Post a listing as a photo + caption (or text-only if no photo). */
    public function broadcast(Listing $listing): bool
    {
        if (!$this->isConfigured()) return false;

        $title       = $listing->title_ar ?? $listing->title_en ?? '—';
        $city        = $listing->city ?? 'السعودية';
        $price       = $listing->price ? number_format((float) $listing->price) . ' ر.س' : 'سعر بالاتفاق';
        $sectionMap  = [
            'fleet' => '🚛 أسطول', 'contracts' => '📄 عقد', 'ma' => '🏢 استحواذ',
            'jobs' => '💼 وظيفة', 'forum' => '💬 نقاش',
        ];
        $section     = $sectionMap[$listing->section] ?? '📌 إعلان';
        $url         = "https://www.nwafizlogi.com/ar/listings/{$listing->id}";

        $caption = "*{$section} جديد على نوافذ*\n\n"
                 . "*{$title}*\n\n"
                 . "📍 {$city}\n"
                 . "💰 {$price}\n\n"
                 . "[افتح الإعلان ←]({$url})";

        // Pick primary image from media[]
        $primary = $listing->media ? collect($listing->media)
            ->firstWhere(fn ($m) => ($m['type'] ?? null) === 'image' && ($m['is_primary'] ?? false)) : null;
        if (!$primary && $listing->media) {
            $primary = collect($listing->media)
                ->firstWhere(fn ($m) => ($m['type'] ?? null) === 'image');
        }

        try {
            if ($primary && !empty($primary['path'])) {
                // sendPhoto with caption
                $photoUrl = "https://nwafiz.creativealphat.com/uploads/{$primary['path']}";
                $res = Http::timeout(8)->post("https://api.telegram.org/bot{$this->token}/sendPhoto", [
                    'chat_id'    => $this->channel,
                    'photo'      => $photoUrl,
                    'caption'    => $caption,
                    'parse_mode' => 'Markdown',
                ]);
            } else {
                // text-only sendMessage
                $res = Http::timeout(8)->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                    'chat_id'                  => $this->channel,
                    'text'                     => $caption,
                    'parse_mode'               => 'Markdown',
                    'disable_web_page_preview' => false,
                ]);
            }

            if (!$res->successful()) {
                Log::warning('Telegram broadcast failed', [
                    'listing_id' => $listing->id,
                    'status'     => $res->status(),
                    'body'       => $res->body(),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram broadcast exception', [
                'listing_id' => $listing->id,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }
}
