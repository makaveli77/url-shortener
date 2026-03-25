<?php

namespace App\MessageHandler;

use App\Entity\ClickEvent;
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
            
            $clickEvent = new ClickEvent();
            $clickEvent->setUrl($url);
            $clickEvent->setIpAddress($message->getIpAddress());
            $clickEvent->setUserAgent($message->getUserAgent());
            $clickEvent->setReferer($message->getReferer());

            $this->entityManager->persist($clickEvent);
            $this->entityManager->flush();
        }
    }
}
