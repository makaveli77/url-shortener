<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class UrlShortenRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url]
        public readonly string $url,
        
        #[Assert\Sequentially([
            new Assert\Length(min: 3, max: 50),
            new Assert\Regex(
                pattern: '/^[a-zA-Z0-9-]+$/',
                message: 'Alias can only contain letters, numbers, and hyphens (e.g. my-portfolio).'
            )
        ])]
        public readonly ?string $alias = null,

        #[Assert\GreaterThan('now', message: 'Expiration date must be in the future.')]
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
    }
}
