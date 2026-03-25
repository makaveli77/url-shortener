<?php

namespace App\Message;

class UrlClick
{
    public function __construct(
        private readonly string $shortCode,
        private readonly ?string $ipAddress = null,
        private readonly ?string $userAgent = null,
        private readonly ?string $referer = null
    ) {
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }
}
