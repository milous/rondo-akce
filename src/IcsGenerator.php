<?php

declare(strict_types=1);

namespace RondoAkce;

use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\Location;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Domain\ValueObject\Uri;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;

final class IcsGenerator
{
    private const LOCATION = 'Winning Group Arena, Brno';
    private const EVENT_DURATION_HOURS = 3;
    private const TIMEZONE = 'Europe/Prague';

    private string $outputPath;

    public function __construct(string $outputPath = 'output/calendar.ics')
    {
        $this->outputPath = $outputPath;
    }

    /**
     * Generate ICS file from events.
     *
     * @param array<array{id: string, title: string, date: string, time: string, url: string, status: string, cancelled_at?: string}> $events
     */
    public function generate(array $events): void
    {
        $calendar = $this->createCalendar($events);
        $this->saveCalendar($calendar);
    }

    /**
     * @param array<array{id: string, title: string, date: string, time: string, url: string, status: string, cancelled_at?: string}> $events
     */
    public function createCalendar(array $events): Calendar
    {
        $calendar = new Calendar();

        foreach ($events as $eventData) {
            $event = $this->createEvent($eventData);
            $calendar->addEvent($event);
        }

        return $calendar;
    }

    /**
     * @param array{id: string, title: string, date: string, time: string, url: string, status: string, cancelled_at?: string} $eventData
     */
    private function createEvent(array $eventData): Event
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);

        // Parse date and time
        $startDateTime = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $eventData['date'] . ' ' . $eventData['time'],
            $timezone
        );

        if ($startDateTime === false) {
            throw new \InvalidArgumentException(
                "Invalid date/time: {$eventData['date']} {$eventData['time']}"
            );
        }

        $endDateTime = $startDateTime->modify('+' . self::EVENT_DURATION_HOURS . ' hours');

        // Create unique identifier
        $uid = new UniqueIdentifier($eventData['id'] . '@winninggrouparena.cz');

        // Create event
        $event = new Event($uid);

        // Set title (with [ZRUSENO] prefix for cancelled events)
        $title = $eventData['status'] === 'cancelled'
            ? '[ZRUSENO] ' . $eventData['title']
            : $eventData['title'];

        $event->setSummary($title);

        // Set time span (with timezone to ensure correct display across timezones)
        $event->setOccurrence(new TimeSpan(
            new DateTime($startDateTime, true),
            new DateTime($endDateTime, true)
        ));

        // Set location
        $event->setLocation(new Location(self::LOCATION));

        // Set URL
        $event->setUrl(new Uri($eventData['url']));

        return $event;
    }

    private function saveCalendar(Calendar $calendar): void
    {
        $outputDir = dirname($this->outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $factory = new CalendarFactory();
        $icsContent = (string) $factory->createCalendar($calendar);

        // Add STATUS:CANCELLED for cancelled events (eluceo/ical doesn't support this directly)
        $icsContent = $this->addCancelledStatus($icsContent);

        file_put_contents($this->outputPath, $icsContent);
    }

    /**
     * Add STATUS:CANCELLED to events with [ZRUSENO] in summary.
     * Handles RFC 5545 line folding (continuation lines start with space/tab).
     */
    private function addCancelledStatus(string $icsContent): string
    {
        $lines = explode("\r\n", $icsContent);
        $result = [];
        $inCancelledSummary = false;
        $needsStatus = false;

        foreach ($lines as $line) {
            // Check if this is a continuation line (starts with space or tab)
            $isContinuation = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");

            // If we were in a cancelled SUMMARY and this is NOT a continuation,
            // we've finished the SUMMARY block - add STATUS now
            if ($needsStatus && !$isContinuation) {
                $result[] = 'STATUS:CANCELLED';
                $needsStatus = false;
                $inCancelledSummary = false;
            }

            $result[] = $line;

            // Check if this is a SUMMARY line with [ZRUSENO]
            if (str_starts_with($line, 'SUMMARY:') && str_contains($line, '[ZRUSENO]')) {
                $inCancelledSummary = true;
                $needsStatus = true;
            }

            // Reset on END:VEVENT (safety fallback)
            if ($line === 'END:VEVENT') {
                $inCancelledSummary = false;
                $needsStatus = false;
            }
        }

        return implode("\r\n", $result);
    }

    /**
     * Generate ICS content as string (for testing).
     *
     * @param array<array{id: string, title: string, date: string, time: string, url: string, status: string, cancelled_at?: string}> $events
     */
    public function generateContent(array $events): string
    {
        $calendar = $this->createCalendar($events);
        $factory = new CalendarFactory();
        $icsContent = (string) $factory->createCalendar($calendar);

        return $this->addCancelledStatus($icsContent);
    }
}
