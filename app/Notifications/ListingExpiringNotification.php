<?php

namespace App\Notifications;

use App\Models\Listing;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ListingExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(public Listing $listing) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'https://nawafez.vercel.app');
        $listingUrl  = "{$frontendUrl}/listings/{$this->listing->id}";
        $title       = $this->listing->title_ar ?? $this->listing->title_en;
        $expiresAt   = $this->listing->expires_at?->format('Y-m-d');

        return (new MailMessage)
            ->subject("إعلانك على نوافذ سينتهي قريباً")
            ->greeting("مرحباً {$notifiable->name_ar},")
            ->line("إعلانك **{$title}** سينتهي في {$expiresAt}.")
            ->line('قم بتجديده الآن للحفاظ على ظهوره في نتائج البحث.')
            ->action('تجديد الإعلان', $listingUrl)
            ->line('شكراً لاستخدامك منصة نوافذ.');
    }
}
