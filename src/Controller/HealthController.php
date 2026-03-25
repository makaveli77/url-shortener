<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Predis\Client as RedisClient;
use OpenApi\Attributes as OA;

class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: "System is healthy",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "status", type: "string", example: "pass"),
                new OA\Property(property: "details", type: "object", properties: [
                    new OA\Property(property: "database", type: "string", example: "pass"),
                    new OA\Property(property: "redis", type: "string", example: "pass")
                ])
            ]
        )
    )]
    #[OA\Response(
        response: 503,
        description: "System is degraded or failing"
    )]
    #[OA\Tag(name: 'System')]
    public function health(
        Connection $connection,
        #[Autowire('%env(REDIS_URL)%')] string $redisUrl
    ): JsonResponse {
        $status = 'pass';
        $details = [
            'database' => 'pass',
            'redis' => 'pass'
        ];

        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Exception $e) {
            $status = 'fail';
            $details['database'] = 'fail';
        }

        try {
            $redis = new RedisClient($redisUrl);
            $redis->ping();
        } catch (\Exception $e) {
            $status = 'fail';
            $details['redis'] = 'fail';
        }

        return new JsonResponse([
            'status' => $status,
            'details' => $details
        ], $status === 'pass' ? 200 : 503);
    }
}
