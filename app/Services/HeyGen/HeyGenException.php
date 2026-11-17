<?php

namespace App\Services\HeyGen;

use RuntimeException;
use Throwable;

class HeyGenException extends RuntimeException
{
    public const CATEGORY_RATE_LIMIT = 'rate_limit';     // 429 - retry after delay

    public const CATEGORY_TRANSIENT = 'transient';       // 5xx / timeouts - safe to retry

    public const CATEGORY_QUOTA = 'quota';               // 402 - quota / credits / plan - never auto-retry

    public const CATEGORY_AUTH = 'auth';                 // 401 / 403 - bad key

    public const CATEGORY_INVALID = 'invalid';           // 400 / 404 / 409 / 422 - fix the request

    public const CATEGORY_CONFIG = 'config';             // local configuration problem (no API key)

    public function __construct(
        string $message,
        public readonly string $category,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRetryable(): bool
    {
        return in_array($this->category, [self::CATEGORY_RATE_LIMIT, self::CATEGORY_TRANSIENT], true);
    }

    public static function missingApiKey(): self
    {
        return new self('HeyGen API key is not configured. Add it in API Settings.', self::CATEGORY_CONFIG);
    }

    public static function fromStatus(int $status, ?string $code, ?string $message, ?int $retryAfter, ?string $requestId): self
    {
        $category = match (true) {
            $status === 429 => self::CATEGORY_RATE_LIMIT,
            $status === 402 => self::CATEGORY_QUOTA,
            in_array($status, [401, 403], true) => self::CATEGORY_AUTH,
            $status >= 500 => self::CATEGORY_TRANSIENT,
            default => self::CATEGORY_INVALID,
        };

        $friendly = match ($code) {
            'unauthorized' => 'HeyGen rejected the API key. Check API Settings.',
            'usage_limit_reached' => 'HeyGen usage limit reached for this workspace or API key.',
            'insufficient_credit' => 'Not enough HeyGen credits to generate speech.',
            'plan_upgrade_required' => 'This voice requires a paid HeyGen plan.',
            'voice_not_ready' => 'The selected voice is still being created in HeyGen (not ACTIVE yet).',
            'voice_training_failed' => 'The selected voice failed training in HeyGen.',
            'voice_expired' => 'The professional voice is disabled because the workspace exceeds its voice slot limit.',
            'rate_limit_exceeded' => 'HeyGen rate limit reached.',
            default => null,
        };

        $text = trim(($friendly ?? 'HeyGen API error').($message ? ' ('.$message.')' : ''));

        return new self(
            $text." [HTTP {$status}".($code ? ", {$code}" : '').']',
            $category, $status, $code, $retryAfter, $requestId,
        );
    }
}
