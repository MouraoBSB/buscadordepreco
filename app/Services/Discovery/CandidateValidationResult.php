<?php

namespace App\Services\Discovery;

class CandidateValidationResult
{
    public function __construct(
        public bool $isValid,
        public ?string $rejectionReason = null,
        public array $matches = []
    ) {}

    public static function valid(array $matches = []): self
    {
        return new self(isValid: true, matches: $matches);
    }

    public static function reject(string $reason): self
    {
        return new self(isValid: false, rejectionReason: $reason);
    }
}
