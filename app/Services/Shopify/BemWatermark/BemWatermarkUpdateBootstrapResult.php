<?php

namespace App\Services\Shopify\BemWatermark;

class BemWatermarkUpdateBootstrapResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly array $context = []
    ) {
    }

    public static function skipped(string $reason, array $context = []): self
    {
        return new self('skipped', $reason, $context);
    }

    public static function completed(string $reason, array $context = []): self
    {
        return new self('completed', $reason, $context);
    }

    public static function noop(string $reason, array $context = []): self
    {
        return new self('noop', $reason, $context);
    }

    public function didChange(): bool
    {
        return $this->status === 'completed';
    }
}
