<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UrlControllerTest extends WebTestCase
{
    public function testShortenUrl(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/shorten',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
             (string) json_encode(['url' => 'https://symfony.com'])
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);
        
        $responseContent = json_decode((string) $client->getResponse()->getContent(), true);
        
        $this->assertArrayHasKey('shortCode', $responseContent);
        $this->assertArrayHasKey('shortUrl', $responseContent);
        
        // Ensure shortCode is alphanumeric and 6 chars long
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{6}$/', $responseContent['shortCode']);
    }

    public function testShortenInvalidUrl(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/shorten',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
             (string) json_encode(['url' => 'not-a-url'])
        );

        // Validation should fail with 422 Unprocessable Entity
        $this->assertResponseStatusCodeSame(422);
    }

    public function testShortenRecursiveUrl(): void
    {
        $client = static::createClient([], [
            'HTTP_HOST' => 'url-shortener.local'
        ]);

        $client->request(
            'POST',
            '/api/shorten',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
             (string) json_encode(['url' => 'http://url-shortener.local/something'])
        );

        $this->assertResponseStatusCodeSame(422);
        
        $content = $client->getResponse()->getContent();
        $responseContent = json_decode((string) $content, true);
        $this->assertIsArray($responseContent, "Response content was: $content");
        $this->assertArrayHasKey('error', $responseContent);
        $this->assertEquals('You cannot shorten a URL from this domain.', $responseContent['error']);
    }

    public function testShortenWithCustomCode(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/shorten',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
             (string) json_encode([
                'url' => 'https://example.com/test-custom',
                'alias' => 'my-portfolio'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $responseContent = json_decode((string) $client->getResponse()->getContent(), true);
        
        $this->assertEquals('my-portfolio', $responseContent['shortCode']);
        
        // Test Conflict (Duplicate)
        $client->request(
            'POST',
            '/api/shorten',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
             (string) json_encode([
                'url' => 'https://example.com/another',
                'alias' => 'my-portfolio'
            ])
        );

        $this->assertResponseStatusCodeSame(409); // CONFLICT
    }

    public function testRedirectUrl(): void
    {
        $client = static::createClient();

        // 1. Create a short URL first
        $client->request(
            'POST',
            '/api/shorten',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
             (string) json_encode(['url' => 'https://github.com'])
        );
        
        $response = json_decode((string) $client->getResponse()->getContent(), true);
        $shortCode = $response['shortCode'];

        // 2. Access the short URL
        $client->request('GET', '/' . $shortCode);

        // 3. Verify Redirect
        $this->assertResponseStatusCodeSame(302);
        $this->assertResponseRedirects('https://github.com');
    }

    public function testRedirectNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', '/NonExistentCode');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testShortenWithExpiration(): void
    {
        $client = static::createClient();

        // Expired 1 day ago
        $expiredDate = (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM);

        $client->request(
            'POST',
            '/api/shorten',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
             (string) json_encode([
                'url' => 'https://example.com/expired',
                'expiresAt' => $expiredDate
            ])
        );

        // Validation should fail because it's set in the past
        $this->assertResponseStatusCodeSame(422);

        // Now test with future date
        $futureDate = (new \DateTimeImmutable('+1 minute'))->format(\DateTimeInterface::ATOM);

        $client->request(
            'POST',
            '/api/shorten',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
             (string) json_encode([
                'url' => 'https://example.com/future',
                'expiresAt' => $futureDate
            ])
        );

        $this->assertResponseStatusCodeSame(201);
    }
    
    public function testRedirectExpiredUrl(): void
    {
        $client = static::createClient();

        // 1. We must mock the expiration because we can't create an expired one via API (due to validation)
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        
        $url = new \App\Entity\Url();
        $url->setOriginalUrl('https://example.com/expired-link');
        $url->setShortCode('expired-code');
        $url->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        
        $em->persist($url);
        $em->flush();

        // 2. Try to access it
        $client->request('GET', '/expired-code');

        // 3. Verify it returns 410 Gone
        $this->assertResponseStatusCodeSame(410);
    }
}
