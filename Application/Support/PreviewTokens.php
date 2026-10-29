<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Support;

/**
 * PreviewTokens — signed, expiring links that let someone without an account
 * read a draft ("send the client the press release before it goes out").
 *
 * Token: base64url(`postId.expiresAt`) + '.' + base64url(HMAC-SHA256). Nothing
 * is stored: the signature is the credential, compared with hash_equals().
 * A token reveals which post it opens and until when — never anything else —
 * and stops working at its expiry or when the secret rotates.
 *
 * With no secret configured the feature FAILS CLOSED: minting throws and
 * every token is refused, rather than signing with an empty key anyone could
 * reproduce.
 */
final readonly class PreviewTokens
{
    /** A week — long enough to circulate a draft, short enough that a leaked link dies. */
    public const int MAX_TTL = 604_800;

    public function __construct(
        private string $secret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->secret !== '';
    }

    public function mint(string $postId, \DateTimeImmutable $now, int $ttlSeconds): string
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('Preview links need POSTS_PREVIEW_SECRET (or APP_KEY) to be set.');
        }

        $ttl     = max(60, min($ttlSeconds, self::MAX_TTL));
        $payload = $postId . '.' . ($now->getTimestamp() + $ttl);

        return self::encode($payload) . '.' . self::encode($this->sign($payload));
    }

    /** The post id a valid, unexpired token opens — or null. */
    public function verify(string $token, \DateTimeImmutable $now): ?string
    {
        if (!$this->isEnabled() || substr_count($token, '.') !== 1) {
            return null;
        }

        [$encodedPayload, $encodedSignature] = explode('.', $token);
        $payload   = self::decode($encodedPayload);
        $signature = self::decode($encodedSignature);

        if ($payload === null || $signature === null || !hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $parts = explode('.', $payload);
        if (count($parts) !== 2 || !ctype_digit($parts[1]) || (int) $parts[1] < $now->getTimestamp()) {
            return null;
        }

        return $parts[0];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'posts.preview|' . $payload, $this->secret, true);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
