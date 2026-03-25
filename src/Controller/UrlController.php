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
            /** @var \App\Entity\User|null $user */
            $user = $this->getUser();
            $shortCode = $this->urlShortener->shorten($requestDto->url, $requestDto->alias, $requestDto->expiresAt, $user, $requestDto->password);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
        
        $shortUrl = rtrim($this->baseUrl, '/') . '/' . $shortCode;

        return $this->json([
            'shortCode' => $shortCode,
            'shortUrl' => $shortUrl
        ], Response::HTTP_CREATED);
    }

    #[Route('/{shortCode}', name: 'app_redirect', methods: ['GET', 'POST'], requirements: ['shortCode' => '[a-zA-Z0-9-]+'])]
    #[OA\Response(
        response: 302,
        description: "Redirects to the original URL"
    )]
    #[OA\Response(
        response: 404,
        description: "URL not found"
    )]
    #[OA\Tag(name: 'URL Shortener')]
    public function redirectUrl(string $shortCode, Request $request): Response
    {
        $resolveData = $this->urlShortener->resolve($shortCode);

        if (!$resolveData) {
            throw $this->createNotFoundException('URL not found');
        }

        $originalUrl = $resolveData['url'];
        $passwordHash = $resolveData['passwordHash'];

        if ($passwordHash) {
            $error = null;
            if ($request->isMethod('POST')) {
                $password = $request->request->get('password', '');
                if (password_verify((string)$password, (string)$passwordHash)) {
                    $this->urlShortener->trackClick(
                        $shortCode, 
                        $request->getClientIp(), 
                        substr($request->headers->get('User-Agent') ?? '', 0, 500), 
                        substr($request->headers->get('referer') ?? '', 0, 500)
                    );
                    return $this->redirect($originalUrl);
                }
                $error = 'Invalid password';
            }

            return $this->render('url/password.html.twig', [
                'shortCode' => $shortCode,
                'error' => $error,
            ]);
        }

        $this->urlShortener->trackClick(
            $shortCode, 
            $request->getClientIp(), 
            substr($request->headers->get('User-Agent') ?? '', 0, 500), 
            substr($request->headers->get('referer') ?? '', 0, 500)
        );
        return $this->redirect($originalUrl);
    }

    #[Route('/api/urls', name: 'api_urls_list', methods: ['GET'])]
    public function listUrls(Request $request, \App\Repository\UrlRepository $urlRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('limit', 10)));
        $offset = ($page - 1) * $limit;

        $urls = $urlRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            $limit,
            $offset
        );

        $total = $urlRepository->count(['user' => $user]);

        $data = [];
        foreach ($urls as $url) {
            $data[] = [
                'shortCode' => $url->getShortCode(),
                'originalUrl' => $url->getOriginalUrl(),
                'clicks' => $url->getClickCount(),
                'createdAt' => $url->getCreatedAt()?->format(\DateTime::ATOM) ?? null,
                'shortUrl' => rtrim($this->baseUrl, '/') . '/' . $url->getShortCode(),
            ];
        }

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => ceil($total / $limit)
            ]
        ]);
    }

    #[Route('/api/urls/{shortCode}', name: 'api_urls_delete', methods: ['DELETE'])]
    public function deleteUrl(string $shortCode, \App\Repository\UrlRepository $urlRepository, \Doctrine\ORM\EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $url = $urlRepository->findOneBy(['shortCode' => $shortCode]);

        if (!$url) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($url->getUser() !== $user) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $em->remove($url);
        $em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
