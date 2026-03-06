<?php

namespace App\MessageHandler;

use App\Message\UrlClick;
use App\Repository\UrlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class UrlClickHandler
{
    public function __construct(
        private UrlRepository $urlRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(UrlClick $message): void
    {
        $shortCode = $message->getShortCode();
        $url = $this->urlRepository->findOneBy(['shortCode' => $shortCode]);

        if ($url) {
            $url->incrementClickCount();
            $this->entityManager->flush();
        }
    }
}
