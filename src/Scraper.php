<?php

declare(strict_types=1);

namespace RondoAkce;

use Symfony\Component\DomCrawler\Crawler;

final class Scraper
{
    private const BASE_URL = 'https://www.winninggrouparena.cz';
    private const CALENDAR_URL = self::BASE_URL . '/kalendar-akci/';
    private const DEFAULT_TIME = '19:00';
    private const PAST_DAYS_THRESHOLD = 7;

    /**
     * @return array{events: array<array{id: string, title: string, date: string, time: string, url: string}>, fetchedMonths: string[]}
     */
    public function scrapeEvents(int $monthsAhead = 12): array
    {
        $calendarResult = $this->getEventUrlsFromCalendar($monthsAhead);
        $eventUrls = $calendarResult['urls'];
        $fetchedMonths = $calendarResult['fetchedMonths'];

        $events = [];

        foreach ($eventUrls as $url) {
            $eventData = $this->scrapeEventDetail($url);
            foreach ($eventData as $event) {
                $events[] = $event;
            }
        }

        return [
            'events' => $events,
            'fetchedMonths' => $fetchedMonths,
        ];
    }

    /**
     * @return array{urls: string[], fetchedMonths: string[]}
     */
    public function getEventUrlsFromCalendar(int $monthsAhead = 12): array
    {
        $urls = [];
        $fetchedMonths = [];
        // Anchor to first day of month to avoid skipping months on 29th-31st
        $firstOfMonth = new \DateTimeImmutable('first day of this month');

        $failedMonths = [];

        for ($i = 0; $i < $monthsAhead; $i++) {
            $date = $firstOfMonth->modify("+{$i} months");
            $month = (int) $date->format('n');
            $year = (int) $date->format('Y');

            $calendarUrl = self::CALENDAR_URL . "?viewmonth={$month}&viewyear={$year}";
            $html = $this->fetchUrl($calendarUrl);

            // Retry once after 2 second delay if first request fails
            if ($html === null) {
                sleep(2);
                $html = $this->fetchUrl($calendarUrl);
            }

            if ($html === null) {
                $failedMonths[] = "{$month}/{$year}";
                continue;
            }

            $fetchedMonths[] = sprintf('%04d-%02d', $year, $month);
            $monthUrls = $this->extractEventUrlsFromHtml($html);
            $urls = array_merge($urls, $monthUrls);
        }

        // Fail if more than half of the months failed (likely a serious issue)
        if (count($failedMonths) > $monthsAhead / 2) {
            throw new \RuntimeException(
                "Too many calendar fetches failed: " . implode(', ', $failedMonths)
            );
        }

        return [
            'urls' => array_unique($urls),
            'fetchedMonths' => $fetchedMonths,
        ];
    }

    /**
     * @return string[]
     */
    public function extractEventUrlsFromHtml(string $html): array
    {
        $crawler = new Crawler($html);
        $urls = [];

        $crawler->filter('a[href*="/event/"]')->each(function (Crawler $node) use (&$urls): void {
            $href = $node->attr('href');
            if ($href !== null) {
                $url = $this->normalizeUrl($href);
                if ($url !== null && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        });

        return $urls;
    }

    /**
     * @return array<array{id: string, title: string, date: string, time: string, url: string}>
     */
    public function scrapeEventDetail(string $url): array
    {
        $html = $this->fetchUrl($url);
        if ($html === null) {
            return [];
        }

        return $this->parseEventDetailHtml($html, $url);
    }

    /**
     * @return array<array{id: string, title: string, date: string, time: string, url: string}>
     */
    public function parseEventDetailHtml(string $html, string $url): array
    {
        $crawler = new Crawler($html);
        $events = [];

        // Extract title
        $title = $this->extractTitle($crawler);
        if ($title === null) {
            return [];
        }

        // Extract event ID from URL
        $id = $this->extractIdFromUrl($url);

        // Extract all dates and times from the page
        $dateTimes = $this->extractDateTimes($crawler, $html);

        if (empty($dateTimes)) {
            return [];
        }

        foreach ($dateTimes as $index => $dateTime) {
            $events[] = [
                'id' => count($dateTimes) > 1 ? "{$id}-{$index}" : $id,
                'title' => $title,
                'date' => $dateTime['date'],
                'time' => $dateTime['time'],
                'url' => $url,
            ];
        }

        return $events;
    }

    private function extractTitle(Crawler $crawler): ?string
    {
        // Try h1 first
        $h1 = $crawler->filter('h1');
        if ($h1->count() > 0) {
            $title = trim($h1->first()->text());
            if ($title !== '') {
                return $title;
            }
        }

        // Try meta title
        $metaTitle = $crawler->filter('meta[property="og:title"]');
        if ($metaTitle->count() > 0) {
            $title = $metaTitle->attr('content');
            if ($title !== null && trim($title) !== '') {
                return trim($title);
            }
        }

        return null;
    }

    /**
     * @return array<array{date: string, time: string}>
     */
    private function extractDateTimes(Crawler $crawler, string $html): array
    {
        $results = [];

        // Pattern: D.M.YYYY or DD.MM.YYYY
        $datePattern = '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/';

        // Pattern: HH:MM or HH.MM (for time)
        $timePattern = '/^(\d{1,2})[:\.](\d{2})$/';

        // Calculate minimum date once
        $minDate = (new \DateTimeImmutable('today'))->modify('-' . self::PAST_DAYS_THRESHOLD . ' days');

        // Strategy 1: Look for dates in <h2> elements and times in following <h3> elements
        // This matches the winninggrouparena.cz structure: <h2>30.1.2026</h2><h3>18.00</h3>
        $h2Elements = $crawler->filter('h2');
        $h2Elements->each(function (Crawler $h2) use (&$results, $datePattern, $timePattern, $minDate): void {
            $dateText = trim($h2->text());

            if (!preg_match($datePattern, $dateText, $dateMatch)) {
                return;
            }

            $day = (int) $dateMatch[1];
            $month = (int) $dateMatch[2];
            $year = (int) $dateMatch[3];

            if (!checkdate($month, $day, $year)) {
                return;
            }

            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $eventDate = new \DateTimeImmutable($dateStr);

            if ($eventDate < $minDate) {
                return;
            }

            // Look for time in the next <h3> sibling
            $time = self::DEFAULT_TIME;
            try {
                $nextNode = $h2->nextAll()->first();
                if ($nextNode->count() > 0 && $nextNode->nodeName() === 'h3') {
                    $timeText = trim($nextNode->text());
                    if (preg_match($timePattern, $timeText, $timeMatch)) {
                        $hour = (int) $timeMatch[1];
                        $minute = (int) $timeMatch[2];
                        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                            $time = sprintf('%02d:%02d', $hour, $minute);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors when looking for next sibling
            }

            $results[] = [
                'date' => $dateStr,
                'time' => $time,
            ];
        });

        return $results;
    }

    private function extractIdFromUrl(string $url): string
    {
        // Extract the event slug from URL like /event/hc-kometa-brno-rytiri-kladno-14/
        if (preg_match('#/event/([^/]+)/?#', $url, $matches)) {
            return $matches[1];
        }

        // Fallback: use hash of URL
        return substr(md5($url), 0, 12);
    }

    private function normalizeUrl(string $href): ?string
    {
        // Skip non-event links
        if (!str_contains($href, '/event/')) {
            return null;
        }

        // Make absolute URL for relative paths
        if (str_starts_with($href, '/')) {
            return self::BASE_URL . $href;
        }

        // Only accept URLs from winninggrouparena.cz (skip ticketportal.cz etc.)
        if (str_starts_with($href, self::BASE_URL)) {
            return $href;
        }

        return null;
    }

    protected function fetchUrl(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (compatible; RondoAkce/1.0)',
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        return $content !== false ? $content : null;
    }
}
