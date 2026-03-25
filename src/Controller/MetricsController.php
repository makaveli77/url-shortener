<?php

namespace App\Controller;

use App\Repository\UrlRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Predis\Client as RedisClient;
use OpenApi\Attributes as OA;

class MetricsController extends AbstractController
{
    #[Route('/api/metrics', name: 'api_metrics', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: "Prometheus metrics format"
    )]
    #[OA\Tag(name: 'System')]
    public function metrics(
        UrlRepository $urlRepository,
        #[Autowire('%env(REDIS_URL)%')] string $redisUrl
    ): Response {
        $totalUrls = $urlRepository->count([]);
        
        $totalClicksObj = $urlRepository->createQueryBuilder('u')
            ->select('SUM(u.clickCount) as totalClicks')
            ->getQuery()
            ->getSingleScalarResult();
        $totalClicks = (int) $totalClicksObj;

        $queueSize = 0;
        try {
            $redis = new RedisClient($redisUrl);
            $len = $redis->xlen('messages');
            if ($len) {
                $queueSize = (int) $len;
            }
        } catch (\Exception $e) {
            // Ignore missing stream or redis connection issues
        }

        $metrics = [
            '# HELP app_urls_total The total number of shortened URLs',
            '# TYPE app_urls_total gauge',
            sprintf('app_urls_total %d', $totalUrls),
            '',
            '# HELP app_clicks_total The total number of URL clicks registered',
            '# TYPE app_clicks_total gauge',
            sprintf('app_clicks_total %d', $totalClicks),
            '',
            '# HELP app_queue_size The number of pending background messages',
            '# TYPE app_queue_size gauge',
            sprintf('app_queue_size %d', $queueSize),
            ''
        ];

        return new Response(implode("\n", $metrics), 200, [
            'Content-Type' => 'text/plain; version=0.0.4'
        ]);
    }
}
