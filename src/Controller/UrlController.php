<?php

namespace App\Controller;

use App\Dto\UrlShortenRequest;
use App\Service\UrlShortener;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class UrlController extends AbstractController
{
    public function __construct(
        private UrlShortener $urlShortener,
        #[Autowire('%env(default::DEFAULT_URI)%')]
        private string $baseUrl = 'http://localhost'
    ) {
    }

    #[Route('/api/shorten', name: 'api_shorten', methods: ['POST'])]
    #[OA\Response(
        response: 201,
        description: "URL successfully shortened",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "shortCode", type: "string", example: "AbCd12"),
                new OA\Property(property: "shortUrl", type: "string", example: "http://localhost:8000/AbCd12")
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: "Validation error"
    )]
    #[OA\Response(
        response: 429,
        description: "Too many requests"
    )]
    #[OA\Tag(name: 'URL Shortener')]
    public function shorten(
        #[MapRequestPayload] UrlShortenRequest $requestDto,
        Request $request,
        #[Autowire(service: 'limiter.api_shorten')] RateLimiterFactory $apiShortenLimiter
    ): JsonResponse
    {
        $clientIp = $request->getClientIp();
        if ($clientIp === null) {
            return $this->json(['error' => 'Unable to determine client IP.'], Response::HTTP_BAD_REQUEST);
        }

        // Prevent infinite loops / shortening our own URLs
        $targetHost = parse_url($requestDto->url, PHP_URL_HOST);
        $requestHost = $request->getHost();
        $baseHost = parse_url($this->baseUrl, PHP_URL_HOST);
        
        if ($targetHost && ($targetHost === $requestHost || $targetHost === $baseHost)) {
            return $this->json(['error' => 'You cannot shorten a URL from this domain.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Limit to 50 requests per hour per IP
        $limiter = $apiShortenLimiter->create($clientIp);
        if (false === $limiter->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException('Rate limit exceeded. Please try again later.');
        }

        try {
            $shortCode = $this->urlShortener->shorten($requestDto->url, $requestDto->alias, $requestDto->expiresAt);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        
        $shortUrl = rtrim($this->baseUrl, '/') . '/' . $shortCode;

        return $this->json([
            'shortCode' => $shortCode,
            'shortUrl' => $shortUrl
        ], Response::HTTP_CREATED);
    }

    #[Route('/{shortCode}', name: 'app_redirect', methods: ['GET'], requirements: ['shortCode' => '[a-zA-Z0-9-]+'])]
    #[OA\Response(
        response: 302,
        description: "Redirects to the original URL"
    )]
    #[OA\Response(
        response: 404,
        description: "URL not found"
    )]
    #[OA\Tag(name: 'URL Shortener')]
    public function redirectUrl(string $shortCode): Response
    {
        $originalUrl = $this->urlShortener->resolve($shortCode);

        if ($originalUrl) {
             $this->urlShortener->trackClick($shortCode);
             return $this->redirect($originalUrl);
        }

        throw $this->createNotFoundException('URL not found');
    }
}
