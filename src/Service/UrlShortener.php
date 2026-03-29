<?php

namespace App\Service;

use App\Entity\Url;
use App\Message\UrlClick;
use App\Repository\UrlRepository;
use App\Repository\BlacklistedDomainRepository;
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
        private MessageBusInterface $messageBus,
        private BlacklistedDomainRepository $blacklistedDomainRepository,
    ) {
    }

    public function shorten(string $originalUrl, ?string $alias = null, ?\DateTimeImmutable $expiresAt = null, ?\App\Entity\User $user = null, ?string $password = null): string
    {
        $domain = parse_url($originalUrl, PHP_URL_HOST);
        if ($domain !== null && $this->blacklistedDomainRepository->findOneBy(['domain' => $domain])) {
            throw new \InvalidArgumentException('This domain is blacklisted and cannot be shortened.');
        }

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
        
        if ($user !== null) {
            $url->setUser($user);
        }
        
        if ($expiresAt !== null) {
            $url->setExpiresAt($expiresAt);
        }
        
        $passwordHash = null;
        if ($password !== null && $password !== '') {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $url->setPasswordHash($passwordHash);
        }
        
        $this->entityManager->persist($url);
        $this->entityManager->flush();

        // Cache it immediately
        $this->cache->get('url_' . $shortCode, function (ItemInterface $item) use ($originalUrl, $expiresAt, $passwordHash) {
            if ($expiresAt !== null) {
                // Determine seconds until expiration
                $ttl = $expiresAt->getTimestamp() - time();
                // Ensure it's not negative though validation shouldn't allow it
                $item->expiresAfter(max(1, min($ttl, 3600 * 24)));
            } else {
                $item->expiresAfter(3600 * 24); // 24 hours
            }
            return [
                'url' => $originalUrl,
                'passwordHash' => $passwordHash,
            ];
        });

        return $shortCode;
    }

    /**
     * @return array{url: string, passwordHash: string|null}|null
     */
    public function resolve(string $shortCode): ?array
    {
        $data = $this->cache->get('url_' . $shortCode, function (ItemInterface $item) use ($shortCode) {
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

            return [
                'url' => (string) $url->getOriginalUrl(),
                'passwordHash' => $url->getPasswordHash(),
            ];
        });

        return $data;
    }

    public function trackClick(string $shortCode, ?string $ipAddress = null, ?string $userAgent = null, ?string $referer = null): void
    {
        // Optimized: Dispatch message to queue for async processing
        $this->messageBus->dispatch(new UrlClick($shortCode, $ipAddress, $userAgent, $referer));
    }

    private function generateShortCode(int $length = 6): string
    {
        return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length);
    }
}
