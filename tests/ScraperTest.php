<?php

declare(strict_types=1);

namespace RondoAkce\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RondoAkce\Scraper;

final class ScraperTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    #[Test]
    public function extractEventUrlsFromHtml(): void
    {
        $html = file_get_contents($this->fixturesDir . '/calendar.html');
        $scraper = new Scraper();

        $urls = $scraper->extractEventUrlsFromHtml($html);

        $this->assertCount(3, $urls);
        $this->assertContains('https://www.winninggrouparena.cz/event/hc-kometa-brno-rytiri-kladno-14/', $urls);
        $this->assertContains('https://www.winninggrouparena.cz/event/koncert-dracula/', $urls);
        $this->assertContains('https://www.winninggrouparena.cz/event/sportovni-akce-2026/', $urls);
    }

    #[Test]
    public function parseEventDetailHtml(): void
    {
        $html = file_get_contents($this->fixturesDir . '/event-detail.html');
        $scraper = new Scraper();

        $events = $scraper->parseEventDetailHtml(
            $html,
            'https://www.winninggrouparena.cz/event/hc-kometa-brno-rytiri-kladno-14/'
        );

        $this->assertCount(1, $events);
        $this->assertSame('hc-kometa-brno-rytiri-kladno-14', $events[0]['id']);
        $this->assertSame('HC Kometa Brno - Rytiri Kladno', $events[0]['title']);
        $this->assertSame('2026-02-15', $events[0]['date']);
        $this->assertSame('18:00', $events[0]['time']);
    }

    #[Test]
    public function parseEventDetailWithMultipleDates(): void
    {
        $html = file_get_contents($this->fixturesDir . '/event-detail-multi-date.html');
        $scraper = new Scraper();

        $events = $scraper->parseEventDetailHtml(
            $html,
            'https://www.winninggrouparena.cz/event/koncert-dracula/'
        );

        $this->assertCount(2, $events);

        // First date
        $this->assertSame('koncert-dracula-0', $events[0]['id']);
        $this->assertSame('Dracula - muzikalova show', $events[0]['title']);
        $this->assertSame('2026-02-17', $events[0]['date']);
        $this->assertSame('19:00', $events[0]['time']);

        // Second date
        $this->assertSame('koncert-dracula-1', $events[1]['id']);
        $this->assertSame('Dracula - muzikalova show', $events[1]['title']);
        $this->assertSame('2026-02-18', $events[1]['date']);
        $this->assertSame('19:00', $events[1]['time']);
    }

    #[Test]
    public function parseEventDetailWithoutTimeFallsBackToDefault(): void
    {
        $html = file_get_contents($this->fixturesDir . '/event-detail-no-time.html');
        $scraper = new Scraper();

        $events = $scraper->parseEventDetailHtml(
            $html,
            'https://www.winninggrouparena.cz/event/sportovni-akce-2026/'
        );

        $this->assertCount(1, $events);
        $this->assertSame('2026-02-25', $events[0]['date']);
        $this->assertSame('19:00', $events[0]['time']); // Default time
    }

    #[Test]
    public function parseInvalidHtmlReturnsEmptyArray(): void
    {
        $scraper = new Scraper();

        $events = $scraper->parseEventDetailHtml(
            '<html><body>No event data here</body></html>',
            'https://www.winninggrouparena.cz/event/invalid/'
        );

        $this->assertEmpty($events);
    }

    #[Test]
    public function extractEventUrlsIgnoresNonEventLinks(): void
    {
        $html = '
            <html>
            <body>
                <a href="/event/valid-event/">Valid Event</a>
                <a href="/contact/">Contact</a>
                <a href="https://example.com/">External</a>
                <a href="/event/another-event/">Another Event</a>
            </body>
            </html>
        ';
        $scraper = new Scraper();

        $urls = $scraper->extractEventUrlsFromHtml($html);

        $this->assertCount(2, $urls);
        $this->assertContains('https://www.winninggrouparena.cz/event/valid-event/', $urls);
        $this->assertContains('https://www.winninggrouparena.cz/event/another-event/', $urls);
    }
}
