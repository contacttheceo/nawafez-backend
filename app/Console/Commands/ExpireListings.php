<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Notifications\ListingExpiringNotification;
use Illuminate\Console\Command;

class ExpireListings extends Command
{
    protected $signature   = 'listings:expire';
    protected $description = 'Expire listings past their expiry date and notify owners 7 days before expiry';

    public function handle(): void
    {
        // 1. Hard-expire listings whose expiry date has passed
        $expired = Listing::where('status', 'active')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $listing) {
            $listing->update(['status' => 'expired']);
        }

        $this->info("Expired {$expired->count()} listing(s).");

        // 2. Notify owners about listings expiring in ~7 days
        $soonExpiring = Listing::where('status', 'active')
            ->whereBetween('expires_at', [now()->addDays(6), now()->addDays(8)])
            ->with('user')
            ->get();

        foreach ($soonExpiring as $listing) {
            try {
                $listing->user->notify(new ListingExpiringNotification($listing));
            } catch (\Throwable $e) {
                $this->error("Notification failed for listing #{$listing->id}: {$e->getMessage()}");
            }
        }

        $this->info("Sent expiry notifications for {$soonExpiring->count()} listing(s).");
    }
}
