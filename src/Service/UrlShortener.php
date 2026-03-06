<?php

namespace App\Service;

use App\Entity\Url;
use App\Message\UrlClick;
use App\Repository\UrlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class UrlShortener
{
    public function __construct(
        private UrlRepository $urlRepository,
        private EntityManagerInterface $entityManager,
        private CacheInterface $cache,
        private MessageBusInterface $messageBus
    ) {
    }

    public function shorten(string $originalUrl, ?string $alias = null, ?\DateTimeImmutable $expiresAt = null): string
    {
        if ($alias !== null) {
            // Check if it already exists
            if ($this->urlRepository->findOneBy(['shortCode' => $alias])) {
                throw new \InvalidArgumentException('This alias is already taken.');
            }
            $shortCode = $alias;
        } else {
            // Generate a random short code
            $shortCode = $this->generateShortCode();
            
            // Ensure uniqueness (simple retry)
            while ($this->urlRepository->findOneBy(['shortCode' => $shortCode])) {
                $shortCode = $this->generateShortCode();
            }
        }

        $url = new Url();
        $url->setOriginalUrl($originalUrl);
        $url->setShortCode($shortCode);
        
        if ($expiresAt !== null) {
            $url->setExpiresAt($expiresAt);
        }
        
        $this->entityManager->persist($url);
        $this->entityManager->flush();

        // Cache it immediately
        $this->cache->get('url_' . $shortCode, function (ItemInterface $item) use ($originalUrl, $expiresAt) {
            if ($expiresAt !== null) {
                // Determine seconds until expiration
                $ttl = $expiresAt->getTimestamp() - time();
                // Ensure it's not negative though validation shouldn't allow it
                $item->expiresAfter(max(1, min($ttl, 3600 * 24)));
            } else {
                $item->expiresAfter(3600 * 24); // 24 hours
            }
            return $originalUrl;
        });

        return $shortCode;
    }

    public function resolve(string $shortCode): ?string
    {
        return $this->cache->get('url_' . $shortCode, function (ItemInterface $item) use ($shortCode) {
            $url = $this->urlRepository->findOneBy(['shortCode' => $shortCode]);
            
            if (!$url) {
                return null;
            }

            if ($url->getExpiresAt() !== null && $url->getExpiresAt() < new \DateTimeImmutable()) {
                throw new \Symfony\Component\HttpKernel\Exception\GoneHttpException('This link has expired.');
            }

            if ($url->getExpiresAt() !== null) {
                $ttl = $url->getExpiresAt()->getTimestamp() - time();
                $item->expiresAfter(max(1, min($ttl, 3600 * 24)));
            } else {
                $item->expiresAfter(3600 * 24);
            }

            return $url->getOriginalUrl();
        });
    }

    public function trackClick(string $shortCode): void
    {
        // Optimized: Dispatch message to queue for async processing
        $this->messageBus->dispatch(new UrlClick($shortCode));
    }

    private function generateShortCode(int $length = 6): string
    {
        return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length);
    }
}
