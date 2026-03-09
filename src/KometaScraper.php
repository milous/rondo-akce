<?php

declare(strict_types=1);

namespace RondoAkce;

use Symfony\Component\DomCrawler\Crawler;

final class KometaScraper
{
    private const BASE_URL = 'https://www.hc-kometa.cz';
    private const DEFAULT_TIME = '19:00';
    private const PAST_DAYS_THRESHOLD = 7;

    /**
     * Get season identifiers for the current and optionally next hockey season.
     * Hockey season runs Sep–Apr, identified by ending year (e.g., 2025/26 → "2026").
     *
     * @return string[] Season identifiers including regular season and playoff (e.g., ['2026', '2026-3'])
     */
    public static function getCurrentSeasons(): array
    {
        $now = new \DateTimeImmutable();
        $month = (int) $now->format('n');
        $year = (int) $now->format('Y');

        // Sep–Dec: season ends next year; Jan–Aug: season ends this year
        $seasonYear = $month >= 9 ? $year + 1 : $year;

        return [
            (string) $seasonYear,       // regular season (Tipsport extraliga)
            $seasonYear . '-3',          // playoff
        ];
    }

    /**
     * @param string[] $seasons Season identifiers (e.g., ['2026-3', '2026'])
     * @return array{events: array<array{id: string, title: string, date: string, time: string, url: string}>, fetchedSeasons: string[]}
     */
    public function scrapeEvents(array $seasons): array
    {
        $events = [];
        $fetchedSeasons = [];
        $minDate = (new \DateTimeImmutable('today'))->modify('-' . self::PAST_DAYS_THRESHOLD . ' days');

        foreach ($seasons as $season) {
            $url = self::BASE_URL . '/zapasy.asp?stats=&sezona=' . urlencode($season) . '&kde=doma&mladez_kategorie=MUZ&mladez_sezona=' . urlencode(substr($season, 0, 4));
            $html = $this->fetchUrl($url);

            if ($html === null) {
                sleep(2);
                $html = $this->fetchUrl($url);
            }

            if ($html === null) {
                continue;
            }

            $fetchedSeasons[] = $season;
            $seasonEvents = $this->parseScheduleHtml($html, $minDate);
            foreach ($seasonEvents as $event) {
                $events[$event['id']] = $event; // Deduplicate by ID across seasons
            }
        }

        return [
            'events' => array_values($events),
            'fetchedSeasons' => $fetchedSeasons,
        ];
    }

    /**
     * @return array<array{id: string, title: string, date: string, time: string, url: string}>
     */
    public function parseScheduleHtml(string $html, ?\DateTimeImmutable $minDate = null): array
    {
        $minDate ??= (new \DateTimeImmutable('today'))->modify('-' . self::PAST_DAYS_THRESHOLD . ' days');
        $crawler = new Crawler($html);
        $events = [];

        $crawler->filter('.schedule__item')->each(function (Crawler $item) use (&$events, $minDate): void {
            $event = $this->parseScheduleItem($item, $minDate);
            if ($event !== null) {
                $events[] = $event;
            }
        });

        return $events;
    }

    /**
     * @return array{id: string, title: string, date: string, time: string, url: string}|null
     */
    private function parseScheduleItem(Crawler $item, \DateTimeImmutable $minDate): ?array
    {
        // Extract date from schedule__date (e.g., "PŘ1, po 9.3.2026")
        $dateNode = $item->filter('.schedule__date');
        if ($dateNode->count() === 0) {
            return null;
        }

        $dateText = trim($dateNode->text());
        $date = $this->parseDate($dateText);
        if ($date === null) {
            return null;
        }

        $eventDate = new \DateTimeImmutable($date);
        if ($eventDate < $minDate) {
            return null;
        }

        // Extract team names
        $teams = [];
        $item->filter('.schedule__team .team--long')->each(function (Crawler $team) use (&$teams): void {
            $teams[] = trim($team->text());
        });

        if (count($teams) < 2) {
            return null;
        }

        $title = $teams[0] . ' - ' . $teams[1];

        // Extract time from schedule__score
        // Score contains either a time (e.g., "18:00") or a match result in an <a> tag (e.g., "4:2")
        // We only want the time, not the score
        $time = self::DEFAULT_TIME;
        $scoreNode = $item->filter('.schedule__score');
        if ($scoreNode->count() > 0) {
            // Any <a> in the score div means it's a played match (result link)
            $resultLink = $scoreNode->filter('a');
            if ($resultLink->count() > 0) {
                // Played match — no future time to extract
            } else {
                $scoreText = trim($scoreNode->first()->text());
                if (preg_match('/(\d{1,2}):(\d{2})/', $scoreText, $timeMatch)) {
                    $hour = (int) $timeMatch[1];
                    $minute = (int) $timeMatch[2];
                    if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                        $time = sprintf('%02d:%02d', $hour, $minute);
                    }
                }
            }
        }

        // Generate ID from date and opponent slug to handle multiple games on same day
        $opponentSlug = $this->slugify($teams[1]);
        $id = 'kometa-' . $date . '-' . $opponentSlug;

        // Extract URL if available
        $url = self::BASE_URL . '/zapasy.asp';
        $linkNode = $item->filter('a[href*="zapas.asp?id="]');
        if ($linkNode->count() > 0) {
            $href = trim($linkNode->attr('href') ?? '');
            if ($href !== '') {
                $url = self::BASE_URL . '/' . ltrim($href, '/ ');
            }
        }

        return [
            'id' => $id,
            'title' => $title,
            'date' => $date,
            'time' => $time,
            'url' => $url,
        ];
    }

    private function slugify(string $text): string
    {
        $slug = mb_strtolower($text);
        // Transliterate common Czech characters
        $slug = strtr($slug, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
            'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
            'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }

    /**
     * Parse date from string like "PŘ1, po 9.3.2026" or "52, pá 28.2.2026"
     */
    private function parseDate(string $text): ?string
    {
        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $matches)) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
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

        if ($content === false) {
            return null;
        }

        // Convert from windows-1250 to UTF-8
        $converted = @iconv('Windows-1250', 'UTF-8//TRANSLIT', $content);

        return $converted !== false ? $converted : $content;
    }
}
