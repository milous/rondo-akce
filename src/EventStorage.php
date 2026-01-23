<?php

declare(strict_types=1);

namespace RondoAkce;

final class EventStorage
{
    private string $dataDir;

    public function __construct(string $dataDir = 'data/events')
    {
        $this->dataDir = rtrim($dataDir, '/');
    }

    /**
     * Synchronize events from scraper with stored data.
     *
     * @param array<array{id: string, title: string, date: string, time: string, url: string}> $scrapedEvents
     * @param string[] $fetchedMonths List of successfully fetched months (format: 'YYYY-MM'). Only events in these months can be cancelled.
     */
    public function sync(array $scrapedEvents, array $fetchedMonths = []): void
    {
        $now = new \DateTimeImmutable();
        $nowString = $now->format('Y-m-d\TH:i:s');
        $today = $now->format('Y-m-d');

        // Group scraped events by date
        $scrapedByDate = [];
        foreach ($scrapedEvents as $event) {
            $date = $event['date'];
            if (!isset($scrapedByDate[$date])) {
                $scrapedByDate[$date] = [];
            }
            $scrapedByDate[$date][$event['id']] = $event;
        }

        // Get all existing date files
        $existingDates = $this->getExistingDates();

        // Process all dates (existing + new)
        $allDates = array_unique(array_merge($existingDates, array_keys($scrapedByDate)));

        foreach ($allDates as $date) {
            $existingData = $this->loadDateFile($date);
            $scrapedForDate = $scrapedByDate[$date] ?? [];

            // Check if this date's month was successfully fetched
            $dateMonth = substr($date, 0, 7); // 'YYYY-MM'
            $canCancel = empty($fetchedMonths) || in_array($dateMonth, $fetchedMonths, true);

            $updatedEvents = $this->mergeEvents(
                $existingData['events'] ?? [],
                $scrapedForDate,
                $date,
                $today,
                $nowString,
                $canCancel
            );

            // Only save if there are events
            if (!empty($updatedEvents)) {
                $this->saveDateFile($date, $updatedEvents, $nowString);
            }
        }
    }

    /**
     * @param array<array{id: string, title: string, time: string, url: string, status: string, cancelled_at?: string}> $existing
     * @param array<string, array{id: string, title: string, date: string, time: string, url: string}> $scraped
     * @return array<array{id: string, title: string, time: string, url: string, status: string, cancelled_at?: string}>
     */
    private function mergeEvents(array $existing, array $scraped, string $date, string $today, string $nowString, bool $canCancel): array
    {
        $result = [];
        $existingById = [];

        // Index existing events by ID
        foreach ($existing as $event) {
            $existingById[$event['id']] = $event;
        }

        // Process scraped events (new or updated)
        foreach ($scraped as $id => $scrapedEvent) {
            $event = [
                'id' => $id,
                'title' => $scrapedEvent['title'],
                'time' => $scrapedEvent['time'],
                'url' => $scrapedEvent['url'],
                'status' => 'active',
            ];

            // If event existed before but was cancelled, reactivate it
            if (isset($existingById[$id]) && $existingById[$id]['status'] === 'cancelled') {
                // Event is back on the website
                $event['status'] = 'active';
            }

            $result[] = $event;
            unset($existingById[$id]);
        }

        // Process remaining existing events (not in scraped data)
        foreach ($existingById as $event) {
            // If date is in the future, event disappeared from website, AND we can cancel (month was fetched)
            if ($date >= $today && $event['status'] === 'active' && $canCancel) {
                $event['status'] = 'cancelled';
                $event['cancelled_at'] = $nowString;
            }
            // Keep the event (either already cancelled, past date, or month not fetched)
            $result[] = $event;
        }

        // Sort by time
        usort($result, fn($a, $b) => $a['time'] <=> $b['time']);

        return $result;
    }

    /**
     * @return string[]
     */
    private function getExistingDates(): array
    {
        $dates = [];
        $pattern = $this->dataDir . '/*.json';

        foreach (glob($pattern) as $file) {
            $basename = basename($file, '.json');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $basename)) {
                $dates[] = $basename;
            }
        }

        return $dates;
    }

    /**
     * @return array{date?: string, updated_at?: string, events?: array<array{id: string, title: string, time: string, url: string, status: string, cancelled_at?: string}>}
     */
    public function loadDateFile(string $date): array
    {
        $filePath = $this->getFilePath($date);

        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @param array<array{id: string, title: string, time: string, url: string, status: string, cancelled_at?: string}> $events
     */
    private function saveDateFile(string $date, array $events, string $updatedAt): void
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }

        $data = [
            'date' => $date,
            'updated_at' => $updatedAt,
            'events' => $events,
        ];

        $filePath = $this->getFilePath($date);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents($filePath, $json . "\n");
    }

    private function getFilePath(string $date): string
    {
        return $this->dataDir . '/' . $date . '.json';
    }

    /**
     * Get all events from all date files.
     *
     * @return array<array{id: string, title: string, date: string, time: string, url: string, status: string, cancelled_at?: string}>
     */
    public function getAllEvents(): array
    {
        $allEvents = [];

        foreach ($this->getExistingDates() as $date) {
            $data = $this->loadDateFile($date);
            if (isset($data['events']) && is_array($data['events'])) {
                foreach ($data['events'] as $event) {
                    $event['date'] = $date;
                    $allEvents[] = $event;
                }
            }
        }

        // Sort by date and time
        usort($allEvents, function ($a, $b) {
            $dateCompare = $a['date'] <=> $b['date'];
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return $a['time'] <=> $b['time'];
        });

        return $allEvents;
    }
}
