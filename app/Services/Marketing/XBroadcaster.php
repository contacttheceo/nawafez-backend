<?php

namespace App\Services\Marketing;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts approved listings to X (Twitter) using API v2.
 *
 * Config (env):
 *   X_BEARER_TOKEN      — App-only Bearer token (read-only API ops)
 *   X_API_KEY           — Consumer key
 *   X_API_SECRET        — Consumer secret
 *   X_ACCESS_TOKEN      — User access token (write perm)
 *   X_ACCESS_SECRET     — User access token secret
 *
 * Posting tweets requires OAuth 1.0a User Context (or OAuth 2.0 with PKCE).
 * This service uses OAuth 1.0a — simpler than OAuth 2 user flow.
 *
 * Trigger: ListingController after admin approval.
 * No-op if creds are missing.
 */
class XBroadcaster
{
    private ?string $apiKey;
    private ?string $apiSecret;
    private ?string $accessToken;
    private ?string $accessSecret;

    public function __construct()
    {
        $this->apiKey       = env('X_API_KEY');
        $this->apiSecret    = env('X_API_SECRET');
        $this->accessToken  = env('X_ACCESS_TOKEN');
        $this->accessSecret = env('X_ACCESS_SECRET');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey)
            && !empty($this->apiSecret)
            && !empty($this->accessToken)
            && !empty($this->accessSecret);
    }

    public function broadcast(Listing $listing): bool
    {
        if (!$this->isConfigured()) return false;

        $tweet = $this->composeTweet($listing);
        $url   = 'https://api.twitter.com/2/tweets';

        try {
            $authHeader = $this->buildOAuthHeader('POST', $url, []);
            $res = Http::timeout(8)
                ->withHeaders([
                    'Authorization' => $authHeader,
                    'Content-Type'  => 'application/json',
                ])
                ->post($url, ['text' => $tweet]);

            if (!$res->successful()) {
                Log::warning('X broadcast failed', [
                    'listing_id' => $listing->id,
                    'status'     => $res->status(),
                    'body'       => $res->body(),
                ]);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('X broadcast exception', [
                'listing_id' => $listing->id,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** Compose a tweet under 280 chars including the link. */
    private function composeTweet(Listing $listing): string
    {
        $title     = $listing->title_ar ?? $listing->title_en ?? '';
        $city      = $listing->city;
        $price     = $listing->price ? number_format((float) $listing->price) . ' ر.س' : null;
        $sectionMap = [
            'fleet' => '🚛', 'contracts' => '📄', 'ma' => '🏢',
            'jobs' => '💼', 'forum' => '💬',
        ];
        $emoji = $sectionMap[$listing->section] ?? '📌';
        $url   = "https://www.nwafizlogi.com/ar/listings/{$listing->id}";

        // t.co shortens links to 23 chars regardless of original length
        $reserved = 23 + 4; // link + spacing
        $available = 280 - $reserved;

        $headline = "{$emoji} {$title}";
        if ($city) $headline .= "\n📍 {$city}";
        if ($price) $headline .= " · 💰 {$price}";

        // Truncate if needed
        if (mb_strlen($headline) > $available) {
            $headline = mb_substr($headline, 0, $available - 1) . '…';
        }

        return "{$headline}\n\n{$url}";
    }

    /**
     * Build OAuth 1.0a "Authorization" header.
     * Reference: https://developer.twitter.com/en/docs/authentication/oauth-1-0a
     */
    private function buildOAuthHeader(string $method, string $url, array $params): string
    {
        $oauth = [
            'oauth_consumer_key'     => $this->apiKey,
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $this->accessToken,
            'oauth_version'          => '1.0',
        ];

        // Build signature base string
        $allParams = array_merge($oauth, $params);
        ksort($allParams);
        $paramString = http_build_query($allParams, '', '&', PHP_QUERY_RFC3986);
        $base = $method . '&' . rawurlencode($url) . '&' . rawurlencode($paramString);
        $key  = rawurlencode($this->apiSecret) . '&' . rawurlencode($this->accessSecret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        $authParts = [];
        foreach ($oauth as $k => $v) {
            $authParts[] = rawurlencode($k) . '="' . rawurlencode($v) . '"';
        }
        return 'OAuth ' . implode(', ', $authParts);
    }
}
