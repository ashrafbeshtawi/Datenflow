<?php

namespace App\Tests\E2E;

use Symfony\Component\Panther\PantherTestCase;

class HomepageTest extends PantherTestCase
{
    public function testHomepageRendersInBrowser(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/');

        self::assertSelectorTextContains('h1', 'Datenflow');
    }
}
