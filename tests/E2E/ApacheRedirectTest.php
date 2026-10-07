<?php

namespace App\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * Checks the /index.php -> clean URL redirect in docker/php/vhost.conf.
 * Plain TestCase, no Symfony kernel: fetch() sends real HTTP requests to the
 * Apache serving the app in the php container (localhost:80). Symfony itself
 * doesn't handle /index.php, so a 301 here can only come from the vhost rule.
 */
class ApacheRedirectTest extends TestCase
{
    private const BASE = 'http://localhost';

    protected function setUp(): void
    {
        if (@fsockopen('localhost', 80, timeout: 1) === false) {
            self::markTestSkipped('No Apache on localhost:80, run inside the php container.');
        }
    }

    public function testFrontControllerUrlRedirectsToCleanUrl(): void
    {
        self::assertSame([301, self::BASE.'/'], $this->fetch('/index.php'));
        self::assertSame([301, self::BASE.'/faq?a=1'], $this->fetch('/index.php/faq?a=1'));
    }

    public function testRedirectKeepsHttpsBehindProxy(): void
    {
        self::assertSame(
            [301, 'https://www.datenflow.de/faq'],
            $this->fetch('/index.php/faq', ['X-Forwarded-Proto: https', 'Host: www.datenflow.de']),
        );
    }

    public function testCleanUrlsAreServedWithoutRedirectLoop(): void
    {
        self::assertSame([200, null], $this->fetch('/'));
        self::assertSame([200, null], $this->fetch('/faq'));
    }

    /** @return array{int, ?string} status code and Location header */
    private function fetch(string $path, array $headers = []): array
    {
        $context = stream_context_create(['http' => [
            'follow_location' => 0,
            'ignore_errors' => true,
            'header' => $headers,
        ]]);
        file_get_contents(self::BASE.$path, false, $context);
        $response = http_get_last_response_headers();

        $location = null;
        foreach ($response as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }

        return [(int) explode(' ', $response[0])[1], $location];
    }
}
