<?php

declare(strict_types=1);

namespace RondoAkce\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RondoAkce\KometaScraper;

final class KometaScraperTest extends TestCase
{
    #[Test]
    public function parseScheduleHtmlExtractsMatches(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/kometa-schedule.html');
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2026-01-01'));

        $this->assertCount(3, $events);

        // First match - ID includes opponent slug
        $this->assertSame('kometa-2026-03-09-banes-motor-ceske-budejovice', $events[0]['id']);
        $this->assertSame('HC Kometa Brno - Banes Motor České Budějovice', $events[0]['title']);
        $this->assertSame('2026-03-09', $events[0]['date']);
        $this->assertSame('18:00', $events[0]['time']);

        // Second match
        $this->assertSame('kometa-2026-03-10-banes-motor-ceske-budejovice', $events[1]['id']);
        $this->assertSame('2026-03-10', $events[1]['date']);
        $this->assertSame('18:00', $events[1]['time']);

        // Third match - no time specified
        $this->assertSame('kometa-2026-03-15-banes-motor-ceske-budejovice', $events[2]['id']);
        $this->assertSame('2026-03-15', $events[2]['date']);
        $this->assertSame('19:00', $events[2]['time']); // Default time
    }

    #[Test]
    public function parseScheduleHtmlSkipsPastMatches(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/kometa-schedule.html');
        $scraper = new KometaScraper();

        // Set min date to after all matches
        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2026-04-01'));

        $this->assertEmpty($events);
    }

    #[Test]
    public function parseScheduleHtmlHandlesEmptyPage(): void
    {
        $html = '<html><body><div class="text">No matches</div></body></html>';
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2026-01-01'));

        $this->assertEmpty($events);
    }

    #[Test]
    public function parseScheduleHtmlExtractsUrl(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/kometa-schedule.html');
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2026-01-01'));

        // First match has a link
        $this->assertSame('https://www.hc-kometa.cz/zapas.asp?id=10055', $events[0]['url']);

        // Second match has no link, falls back to default
        $this->assertSame('https://www.hc-kometa.cz/zapasy.asp', $events[1]['url']);
    }

    #[Test]
    public function parseScheduleDoesNotConfuseScoreWithTime(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/kometa-schedule-with-results.html');
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2025-01-01'));

        // Played matches (score 4:2, 1:2pp) should use default time, not the score
        // Future match should have the actual time 17:30
        $playedMatch1 = null;
        $futureMatch = null;
        $playedMatch2 = null;
        foreach ($events as $event) {
            if ($event['date'] === '2025-09-10') {
                $playedMatch1 = $event;
            } elseif ($event['date'] === '2099-02-28') {
                $futureMatch = $event;
            } elseif ($event['date'] === '2025-10-11') {
                $playedMatch2 = $event;
            }
        }

        // Played match: should NOT have "04:02" from score "4:2"
        $this->assertNotNull($playedMatch1);
        $this->assertSame('19:00', $playedMatch1['time']); // Default time

        // Future match: should have actual time
        $this->assertNotNull($futureMatch);
        $this->assertSame('17:30', $futureMatch['time']);

        // Played match with overtime: should NOT have "01:02" from score "1:2pp"
        $this->assertNotNull($playedMatch2);
        $this->assertSame('19:00', $playedMatch2['time']); // Default time
    }

    #[Test]
    public function parseScheduleHandlesScheduleItemWithMissingTeams(): void
    {
        $html = '
        <html><body>
        <div class="schedule__item">
            <div class="schedule__date">PŘ1, po 9.3.2026</div>
            <div class="schedule__team">
                <span class="team--long">HC Kometa Brno</span>
            </div>
        </div>
        </body></html>';
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2026-01-01'));

        // Should skip item with only one team
        $this->assertEmpty($events);
    }

    #[Test]
    public function parseScheduleHandlesScheduleItemWithMissingDate(): void
    {
        $html = '
        <html><body>
        <div class="schedule__item">
            <div class="schedule__team">
                <span class="team--long">HC Kometa Brno</span>
                <span class="team--long">HC Verva Litvínov</span>
            </div>
        </div>
        </body></html>';
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2026-01-01'));

        $this->assertEmpty($events);
    }

    #[Test]
    public function parseScheduleHandlesInvalidDate(): void
    {
        $html = '
        <html><body>
        <div class="schedule__item">
            <div class="schedule__date">PŘ1, po 31.2.2026</div>
            <div class="schedule__team">
                <span class="team--long">HC Kometa Brno</span>
                <span class="team--long">HC Verva Litvínov</span>
            </div>
        </div>
        </body></html>';
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2026-01-01'));

        // Feb 31 is invalid
        $this->assertEmpty($events);
    }

    #[Test]
    public function parseScheduleUniqueIdPerOpponentOnSameDay(): void
    {
        // Two different opponents on the same day (e.g., preseason doubleheader)
        $html = '
        <html><body>
        <div class="schedule__item">
            <div class="schedule__date">po 9.3.2099</div>
            <div class="schedule__team">
                <span class="team--long">HC Kometa Brno</span>
                <span class="team--long">HC Sparta Praha</span>
            </div>
            <div class="schedule__score">14:00</div>
        </div>
        <div class="schedule__item">
            <div class="schedule__date">po 9.3.2099</div>
            <div class="schedule__team">
                <span class="team--long">HC Kometa Brno</span>
                <span class="team--long">HC Verva Litvínov</span>
            </div>
            <div class="schedule__score">18:00</div>
        </div>
        </body></html>';
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2099-01-01'));

        $this->assertCount(2, $events);
        $this->assertNotSame($events[0]['id'], $events[1]['id']);
        $this->assertSame('kometa-2099-03-09-hc-sparta-praha', $events[0]['id']);
        $this->assertSame('kometa-2099-03-09-hc-verva-litvinov', $events[1]['id']);
    }

    #[Test]
    public function parseScheduleHandlesResultLinkWithoutClass(): void
    {
        // Score link without win/loss/draw class (edge case)
        $html = '
        <html><body>
        <div class="schedule__item">
            <div class="schedule__date">1.kolo, st 10.9.2099</div>
            <div class="schedule__team">
                <span class="team--long">HC Kometa Brno</span>
                <span class="team--long">HC Verva Litvínov</span>
            </div>
            <div class="schedule__score">
                <a href="zapas.asp?id=9999">4:2</a>
            </div>
        </div>
        </body></html>';
        $scraper = new KometaScraper();

        $events = $scraper->parseScheduleHtml($html, new \DateTimeImmutable('2099-01-01'));

        $this->assertCount(1, $events);
        // Should NOT parse "4:2" as time "04:02"
        $this->assertSame('19:00', $events[0]['time']);
    }

    #[Test]
    public function getCurrentSeasonsReturnsRegularAndPlayoff(): void
    {
        $seasons = KometaScraper::getCurrentSeasons();

        $this->assertCount(2, $seasons);

        $month = (int) date('n');
        $year = (int) date('Y');
        $expectedYear = $month >= 9 ? $year + 1 : $year;

        $this->assertSame((string) $expectedYear, $seasons[0]);
        $this->assertSame($expectedYear . '-3', $seasons[1]);
    }
}
