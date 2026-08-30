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
        self::assertSelectorIsVisible('.slot-nav');

        // Jump to a future week: always past the 24h lead time, and each run
        // books the first free slot, so earlier runs' slots are simply skipped.
        $monday = (new \DateTimeImmutable('monday next week', new \DateTimeZone('Europe/Berlin')))
            ->modify('+1 week')->format('Y-m-d');
        $client->request('GET', '/termin?week='.$monday);

        $client->getCrawler()->filter('.slot')->first()->click();

        $client->submitForm('Termin buchen', [
            'name' => 'E2E Tester',
            'company' => 'Test Gastro GmbH',
            'email' => 'e2e@example.com',
            'message' => 'Unsere Reservierungen laufen noch über den Anrufbeantworter.',
        ]);

        $client->waitFor('.form-success');
        self::assertSelectorTextContains('.form-success', 'Termin gebucht');
    }
}
