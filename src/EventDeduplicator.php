<?php

declare(strict_types=1);

namespace RondoAkce;

final class EventDeduplicator
{
    /**
     * Deduplicate events from multiple sources.
     * When two events are on the same date and both are about HC Kometa,
     * keep the one from the primary source (Winning Group Arena).
     *
     * @param array<array{id: string, title: string, date: string, time: string, url: string}> $primaryEvents Events from Winning Group Arena (preferred)
     * @param array<array{id: string, title: string, date: string, time: string, url: string}> $secondaryEvents Events from HC Kometa website
     * @return array<array{id: string, title: string, date: string, time: string, url: string}>
     */
    public function deduplicate(array $primaryEvents, array $secondaryEvents): array
    {
        // Index primary Kometa events by date
        $primaryKometaDates = [];
        foreach ($primaryEvents as $event) {
            if ($this->isKometaEvent($event['title'])) {
                $primaryKometaDates[$event['date']] = true;
            }
        }

        // Filter secondary events: only add if no primary Kometa event on the same date
        $result = $primaryEvents;
        foreach ($secondaryEvents as $event) {
            if (!isset($primaryKometaDates[$event['date']])) {
                $result[] = $event;
            }
        }

        return $result;
    }

    private function isKometaEvent(string $title): bool
    {
        return stripos($title, 'kometa') !== false;
    }
}
