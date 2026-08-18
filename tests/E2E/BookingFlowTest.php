<?php

namespace App\Tests\E2E;

use Symfony\Component\Panther\PantherTestCase;

class BookingFlowTest extends PantherTestCase
{
    public function testVisitorCanBookACallFromTheHomepage(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/');

        $client->clickLink('Kostenloses Gespräch buchen');
        self::assertSelectorIsVisible('form textarea[name="message"]');

        $client->submitForm('Gespräch anfragen', [
            'name' => 'E2E Tester',
            'company' => 'Test Gastro GmbH',
            'email' => 'e2e@example.com',
            'message' => 'Unsere Reservierungen laufen noch über den Anrufbeantworter.',
        ]);

        self::assertSelectorTextContains('.form-success', 'Danke');
    }
}
