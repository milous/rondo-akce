<?php

declare(strict_types=1);

namespace RondoAkce\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RondoAkce\EventStorage;

final class EventStorageTest extends TestCase
{
    private string $tempDir;
    private EventStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rondo-akce-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->storage = new EventStorage($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    #[Test]
    public function syncCreatesNewEventsWithActiveStatus(): void
    {
        $events = [
            [
                'id' => 'event-1',
                'title' => 'Test Event',
                'date' => '2026-01-15',
                'time' => '18:00',
                'url' => 'https://example.com/event/event-1/',
            ],
        ];

        $this->storage->sync($events);

        $data = $this->storage->loadDateFile('2026-01-15');
        $this->assertCount(1, $data['events']);
        $this->assertSame('active', $data['events'][0]['status']);
        $this->assertSame('event-1', $data['events'][0]['id']);
        $this->assertSame('Test Event', $data['events'][0]['title']);
    }

    #[Test]
    public function syncMarksMissingFutureEventsAsCancelled(): void
    {
        // Create an existing event for a future date
        $futureDate = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');

        $existingEvents = [
            [
                'id' => 'event-1',
                'title' => 'Existing Event',
                'date' => $futureDate,
                'time' => '18:00',
                'url' => 'https://example.com/event/event-1/',
            ],
        ];

        $this->storage->sync($existingEvents);

        // Sync again with empty events (event disappeared from website)
        $this->storage->sync([]);

        $data = $this->storage->loadDateFile($futureDate);
        $this->assertCount(1, $data['events']);
        $this->assertSame('cancelled', $data['events'][0]['status']);
        $this->assertArrayHasKey('cancelled_at', $data['events'][0]);
    }

    #[Test]
    public function syncPreservesPastEventsUnchanged(): void
    {
        // Manually create a past event file
        $pastDate = (new \DateTimeImmutable('-10 days'))->format('Y-m-d');
        $filePath = $this->tempDir . '/' . $pastDate . '.json';

        $pastData = [
            'date' => $pastDate,
            'updated_at' => '2026-01-01T10:00:00',
            'events' => [
                [
                    'id' => 'past-event',
                    'title' => 'Past Event',
                    'time' => '18:00',
                    'url' => 'https://example.com/event/past-event/',
                    'status' => 'active',
                ],
            ],
        ];

        file_put_contents($filePath, json_encode($pastData, JSON_PRETTY_PRINT));

        // Sync with empty events
        $this->storage->sync([]);

        // Past event should remain active (not marked as cancelled)
        $data = $this->storage->loadDateFile($pastDate);
        $this->assertSame('active', $data['events'][0]['status']);
    }

    #[Test]
    public function syncUpdatesExistingEventDetails(): void
    {
        $futureDate = (new \DateTimeImmutable('+5 days'))->format('Y-m-d');

        // First sync
        $events = [
            [
                'id' => 'event-1',
                'title' => 'Original Title',
                'date' => $futureDate,
                'time' => '18:00',
                'url' => 'https://example.com/event/event-1/',
            ],
        ];
        $this->storage->sync($events);

        // Second sync with updated details
        $events[0]['title'] = 'Updated Title';
        $events[0]['time'] = '19:00';
        $this->storage->sync($events);

        $data = $this->storage->loadDateFile($futureDate);
        $this->assertSame('Updated Title', $data['events'][0]['title']);
        $this->assertSame('19:00', $data['events'][0]['time']);
    }

    #[Test]
    public function syncHandlesMultipleEventsOnSameDay(): void
    {
        $date = '2026-02-15';

        $events = [
            [
                'id' => 'event-1',
                'title' => 'Morning Event',
                'date' => $date,
                'time' => '10:00',
                'url' => 'https://example.com/event/event-1/',
            ],
            [
                'id' => 'event-2',
                'title' => 'Evening Event',
                'date' => $date,
                'time' => '20:00',
                'url' => 'https://example.com/event/event-2/',
            ],
        ];

        $this->storage->sync($events);

        $data = $this->storage->loadDateFile($date);
        $this->assertCount(2, $data['events']);

        // Events should be sorted by time
        $this->assertSame('10:00', $data['events'][0]['time']);
        $this->assertSame('20:00', $data['events'][1]['time']);
    }

    #[Test]
    public function syncHandlesEventDateChange(): void
    {
        $oldDate = (new \DateTimeImmutable('+5 days'))->format('Y-m-d');
        $newDate = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');

        // First sync with event on old date
        $events = [
            [
                'id' => 'event-1',
                'title' => 'Moving Event',
                'date' => $oldDate,
                'time' => '18:00',
                'url' => 'https://example.com/event/event-1/',
            ],
        ];
        $this->storage->sync($events);

        // Second sync with event on new date (different ID due to re-scrape)
        $events = [
            [
                'id' => 'event-1-new',
                'title' => 'Moving Event',
                'date' => $newDate,
                'time' => '18:00',
                'url' => 'https://example.com/event/event-1/',
            ],
        ];
        $this->storage->sync($events);

        // Old date should have cancelled event
        $oldData = $this->storage->loadDateFile($oldDate);
        $this->assertSame('cancelled', $oldData['events'][0]['status']);

        // New date should have active event
        $newData = $this->storage->loadDateFile($newDate);
        $this->assertSame('active', $newData['events'][0]['status']);
    }

    #[Test]
    public function getAllEventsReturnsAllEventsFromAllFiles(): void
    {
        $events = [
            [
                'id' => 'event-1',
                'title' => 'Event 1',
                'date' => '2026-01-15',
                'time' => '18:00',
                'url' => 'https://example.com/event/event-1/',
            ],
            [
                'id' => 'event-2',
                'title' => 'Event 2',
                'date' => '2026-01-20',
                'time' => '19:00',
                'url' => 'https://example.com/event/event-2/',
            ],
        ];

        $this->storage->sync($events);

        $allEvents = $this->storage->getAllEvents();

        $this->assertCount(2, $allEvents);
        $this->assertSame('2026-01-15', $allEvents[0]['date']);
        $this->assertSame('2026-01-20', $allEvents[1]['date']);
    }

    #[Test]
    public function syncReactivatesCancelledEventIfItReappearsOnWebsite(): void
    {
        $futureDate = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');

        // First sync
        $events = [
            [
                'id' => 'event-1',
                'title' => 'Test Event',
                'date' => $futureDate,
                'time' => '18:00',
                'url' => 'https://example.com/event/event-1/',
            ],
        ];
        $this->storage->sync($events);

        // Event disappears
        $this->storage->sync([]);
        $data = $this->storage->loadDateFile($futureDate);
        $this->assertSame('cancelled', $data['events'][0]['status']);

        // Event reappears
        $this->storage->sync($events);
        $data = $this->storage->loadDateFile($futureDate);
        $this->assertSame('active', $data['events'][0]['status']);
        $this->assertArrayNotHasKey('cancelled_at', $data['events'][0]);
    }

    #[Test]
    public function syncDoesNotCancelEventsInUnfetchedMonths(): void
    {
        // Create events in two different months
        $januaryDate = '2026-01-15';
        $februaryDate = '2026-02-15';

        $events = [
            [
                'id' => 'january-event',
                'title' => 'January Event',
                'date' => $januaryDate,
                'time' => '18:00',
                'url' => 'https://example.com/event/january/',
            ],
            [
                'id' => 'february-event',
                'title' => 'February Event',
                'date' => $februaryDate,
                'time' => '19:00',
                'url' => 'https://example.com/event/february/',
            ],
        ];

        // First sync - both events exist
        $this->storage->sync($events, ['2026-01', '2026-02']);

        // Second sync - only January was fetched (February fetch failed)
        // Only January event in scraped data
        $this->storage->sync(
            [
                [
                    'id' => 'january-event',
                    'title' => 'January Event',
                    'date' => $januaryDate,
                    'time' => '18:00',
                    'url' => 'https://example.com/event/january/',
                ],
            ],
            ['2026-01'] // Only January was successfully fetched
        );

        // January event should remain active (it's in scraped data)
        $januaryData = $this->storage->loadDateFile($januaryDate);
        $this->assertSame('active', $januaryData['events'][0]['status']);

        // February event should remain active (month was not fetched, so we can't cancel)
        $februaryData = $this->storage->loadDateFile($februaryDate);
        $this->assertSame('active', $februaryData['events'][0]['status']);
        $this->assertArrayNotHasKey('cancelled_at', $februaryData['events'][0]);
    }

    #[Test]
    public function syncCancelsEventsOnlyInFetchedMonths(): void
    {
        $futureDate = '2026-03-15';

        // Create an event
        $events = [
            [
                'id' => 'march-event',
                'title' => 'March Event',
                'date' => $futureDate,
                'time' => '18:00',
                'url' => 'https://example.com/event/march/',
            ],
        ];

        $this->storage->sync($events, ['2026-03']);

        // Event disappears but March was successfully fetched
        $this->storage->sync([], ['2026-03']);

        // Event should be cancelled (March was fetched and event is missing)
        $data = $this->storage->loadDateFile($futureDate);
        $this->assertSame('cancelled', $data['events'][0]['status']);
        $this->assertArrayHasKey('cancelled_at', $data['events'][0]);
    }
}
