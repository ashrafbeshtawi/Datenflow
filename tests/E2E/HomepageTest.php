<?php

namespace App\Tests\E2E;

use Symfony\Component\Panther\PantherTestCase;

class HomepageTest extends PantherTestCase
{
    public function testHomepageRendersInBrowser(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/');

        self::assertSelectorTextContains('h1', 'kompliziert');
        self::assertSelectorIsVisible('.hero .btn-primary');
    }

    public function testBookingCtaLeadsToBookingForm(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/');
        $client->clickLink('Kostenloses Gespräch buchen');

        self::assertSelectorIsVisible('form textarea[name="message"]');
    }
}
