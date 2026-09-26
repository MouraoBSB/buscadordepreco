<?php

namespace App\Services\Discovery\DTOs;

class RawCandidateDto
{
    public function __construct(
        public string $url,
        public string $title,
        public ?float $price = null,
        public ?string $storeDomain = null,
        public ?string $storeName = null,
        public ?string $seller = null,
        public ?string $sellerType = null,
        public ?string $snippet = null,
        public string $provider = 'unknown',
        public array $rawPayload = []
    ) {}

    public function __get(string $name): mixed
    {
        if ($name === 'urlHash') {
            return $this->getUrlHash();
        }
        if ($name === 'domain') {
            return $this->getDomain();
        }

        return null;
    }

    public function getUrlHash(): string
    {
        // Normalize URL for deduplication: remove trailing slash and common tracking parameters
        $cleanUrl = strtok($this->url, '?');
        $cleanUrl = rtrim($cleanUrl, '/');

        return hash('sha256', mb_strtolower($cleanUrl, 'UTF-8'));
    }

    public function getDomain(): string
    {
        if ($this->storeDomain) {
            return $this->storeDomain;
        }

        $host = parse_url($this->url, PHP_URL_HOST);
        if ($host) {
            return preg_replace('/^www\./i', '', strtolower($host));
        }

        return 'unknown';
    }
}
