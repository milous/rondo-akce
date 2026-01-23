<?php

declare(strict_types=1);

namespace RondoAkce\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RondoAkce\IcsGenerator;

final class IcsGeneratorTest extends TestCase
{
    private IcsGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new IcsGenerator();
    }

    #[Test]
    public function generateContentCreatesValidIcsFormat(): void
    {
        $events = [
            [
                'id' => 'test-event',
                'title' => 'Test Event',
                'date' => '2026-01-15',
                'time' => '18:00',
                'url' => 'https://example.com/event/test-event/',
                'status' => 'active',
            ],
        ];

        $ics = $this->generator->generateContent($events);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Test Event', $ics);
        $this->assertStringContainsString('LOCATION:Winning Group Arena', $ics);
        $this->assertStringContainsString('URL:https://example.com/event/test-event/', $ics);
        $this->assertStringContainsString('UID:test-event@winninggrouparena.cz', $ics);
    }

    #[Test]
    public function generateContentHandlesCancelledEvents(): void
    {
        $events = [
            [
                'id' => 'cancelled-event',
                'title' => 'Cancelled Event',
                'date' => '2026-01-20',
                'time' => '19:00',
                'url' => 'https://example.com/event/cancelled-event/',
                'status' => 'cancelled',
                'cancelled_at' => '2026-01-15T10:00:00',
            ],
        ];

        $ics = $this->generator->generateContent($events);

        $this->assertStringContainsString('SUMMARY:[ZRUSENO] Cancelled Event', $ics);
        $this->assertStringContainsString('STATUS:CANCELLED', $ics);
    }

    #[Test]
    public function generateContentHandlesUtf8Characters(): void
    {
        $events = [
            [
                'id' => 'czech-event',
                'title' => 'HC Kometa Brno - Ryt\u00edri Kladno',
                'date' => '2026-01-25',
                'time' => '18:00',
                'url' => 'https://example.com/event/czech-event/',
                'status' => 'active',
            ],
        ];

        $ics = $this->generator->generateContent($events);

        $this->assertStringContainsString('Kometa', $ics);
    }

    #[Test]
    public function generateContentSetsCorrectTimespan(): void
    {
        $events = [
            [
                'id' => 'timed-event',
                'title' => 'Timed Event',
                'date' => '2026-01-15',
                'time' => '18:00',
                'url' => 'https://example.com/event/timed-event/',
                'status' => 'active',
            ],
        ];

        $ics = $this->generator->generateContent($events);

        // Event starts at 18:00 and ends at 21:00 (3 hours later)
        $this->assertStringContainsString('DTSTART', $ics);
        $this->assertStringContainsString('DTEND', $ics);
    }

    #[Test]
    public function generateContentHandlesMultipleEvents(): void
    {
        $events = [
            [
                'id' => 'event-1',
                'title' => 'Event One',
                'date' => '2026-01-15',
                'time' => '18:00',
                'url' => 'https://example.com/event/event-1/',
                'status' => 'active',
            ],
            [
                'id' => 'event-2',
                'title' => 'Event Two',
                'date' => '2026-01-20',
                'time' => '19:00',
                'url' => 'https://example.com/event/event-2/',
                'status' => 'active',
            ],
        ];

        $ics = $this->generator->generateContent($events);

        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertSame(2, substr_count($ics, 'END:VEVENT'));
        $this->assertStringContainsString('SUMMARY:Event One', $ics);
        $this->assertStringContainsString('SUMMARY:Event Two', $ics);
    }

    #[Test]
    public function generateWritesIcsFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test-calendar-' . uniqid() . '.ics';
        $generator = new IcsGenerator($tempFile);

        $events = [
            [
                'id' => 'file-test-event',
                'title' => 'File Test Event',
                'date' => '2026-02-01',
                'time' => '20:00',
                'url' => 'https://example.com/event/file-test-event/',
                'status' => 'active',
            ],
        ];

        $generator->generate($events);

        $this->assertFileExists($tempFile);
        $content = file_get_contents($tempFile);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $content);
        $this->assertStringContainsString('SUMMARY:File Test Event', $content);

        unlink($tempFile);
    }
}
