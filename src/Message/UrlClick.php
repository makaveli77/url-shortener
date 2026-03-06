<?php

namespace App\Message;

class UrlClick
{
    public function __construct(
        private readonly string $shortCode,
    ) {
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }
}
