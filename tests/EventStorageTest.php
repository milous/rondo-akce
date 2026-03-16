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
        // Create events in two different months (both in the future)
        $month1Date = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $month2Date = (new \DateTimeImmutable('+70 days'))->format('Y-m-d');
        $month1 = substr($month1Date, 0, 7);
        $month2 = substr($month2Date, 0, 7);

        $events = [
            [
                'id' => 'month1-event',
                'title' => 'Month 1 Event',
                'date' => $month1Date,
                'time' => '18:00',
                'url' => 'https://example.com/event/month1/',
            ],
            [
                'id' => 'month2-event',
                'title' => 'Month 2 Event',
                'date' => $month2Date,
                'time' => '19:00',
                'url' => 'https://example.com/event/month2/',
            ],
        ];

        // First sync - both events exist
        $this->storage->sync($events, [$month1, $month2]);

        // Second sync - only month1 was fetched (month2 fetch failed)
        // Only month1 event in scraped data
        $this->storage->sync(
            [
                [
                    'id' => 'month1-event',
                    'title' => 'Month 1 Event',
                    'date' => $month1Date,
                    'time' => '18:00',
                    'url' => 'https://example.com/event/month1/',
                ],
            ],
            [$month1] // Only month1 was successfully fetched
        );

        // Month1 event should remain active (it's in scraped data)
        $month1Data = $this->storage->loadDateFile($month1Date);
        $this->assertSame('active', $month1Data['events'][0]['status']);

        // Month2 event should remain active (month was not fetched, so we can't cancel)
        $month2Data = $this->storage->loadDateFile($month2Date);
        $this->assertSame('active', $month2Data['events'][0]['status']);
        $this->assertArrayNotHasKey('cancelled_at', $month2Data['events'][0]);
    }

    #[Test]
    public function syncCancelsEventsOnlyInFetchedMonths(): void
    {
        $futureDate = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $futureMonth = substr($futureDate, 0, 7);

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

        $this->storage->sync($events, [$futureMonth]);

        // Event disappears but its month was successfully fetched
        $this->storage->sync([], [$futureMonth]);

        // Event should be cancelled (March was fetched and event is missing)
        $data = $this->storage->loadDateFile($futureDate);
        $this->assertSame('cancelled', $data['events'][0]['status']);
        $this->assertArrayHasKey('cancelled_at', $data['events'][0]);
    }

    #[Test]
    public function syncCancelsKometaOnlyEventWhenItDisappearsAndMonthWasFetched(): void
    {
        $futureDate = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $futureMonth = substr($futureDate, 0, 7);

        // First sync: WGA concert + Kometa-only game (not on WGA)
        $events = [
            [
                'id' => 'koncert-dracula',
                'title' => 'Dracula show',
                'date' => $futureDate,
                'time' => '20:00',
                'url' => 'https://www.winninggrouparena.cz/event/koncert-dracula/',
            ],
            [
                'id' => 'kometa-' . $futureDate . '-motor-cb',
                'title' => 'HC Kometa Brno - Motor CB',
                'date' => $futureDate,
                'time' => '18:00',
                'url' => 'https://www.hc-kometa.cz/zapas.asp?id=10055',
            ],
        ];
        $this->storage->sync($events, [$futureMonth]);

        $data = $this->storage->loadDateFile($futureDate);
        $this->assertCount(2, $data['events']);

        // Second sync: Kometa game disappeared (e.g., rescheduled), WGA concert stays
        $this->storage->sync([
            [
                'id' => 'koncert-dracula',
                'title' => 'Dracula show',
                'date' => $futureDate,
                'time' => '20:00',
                'url' => 'https://www.winninggrouparena.cz/event/koncert-dracula/',
            ],
        ], [$futureMonth]);

        $data = $this->storage->loadDateFile($futureDate);
        $this->assertCount(2, $data['events']);

        $eventsByStatus = [];
        foreach ($data['events'] as $event) {
            $eventsByStatus[$event['id']] = $event['status'];
        }

        $this->assertSame('active', $eventsByStatus['koncert-dracula']);
        $this->assertSame('cancelled', $eventsByStatus['kometa-' . $futureDate . '-motor-cb']);
    }

    #[Test]
    public function syncDoesNotCancelKometaEventWhenMonthWasNotFetched(): void
    {
        $futureDate = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $futureMonth = substr($futureDate, 0, 7);
        $otherMonth = (new \DateTimeImmutable('+70 days'))->format('Y-m');

        // First sync: Kometa-only game
        $events = [
            [
                'id' => 'kometa-' . $futureDate . '-motor-cb',
                'title' => 'HC Kometa Brno - Motor CB',
                'date' => $futureDate,
                'time' => '18:00',
                'url' => 'https://www.hc-kometa.cz/zapas.asp?id=10055',
            ],
        ];
        $this->storage->sync($events, [$futureMonth]);

        // Second sync: empty scraped data, but this event's month was NOT fetched
        $this->storage->sync([], [$otherMonth]);

        $data = $this->storage->loadDateFile($futureDate);
        $this->assertSame('active', $data['events'][0]['status']);
        $this->assertArrayNotHasKey('cancelled_at', $data['events'][0]);
    }

    #[Test]
    public function syncReplacesKometaEventWithWgaEventOnSameDay(): void
    {
        $futureDate = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $futureMonth = substr($futureDate, 0, 7);

        // First sync: only Kometa source has the game
        $this->storage->sync([
            [
                'id' => 'kometa-' . $futureDate . '-motor-cb',
                'title' => 'HC Kometa Brno - Motor CB',
                'date' => $futureDate,
                'time' => '18:00',
                'url' => 'https://www.hc-kometa.cz/zapas.asp?id=10055',
            ],
        ], [$futureMonth]);

        // Second sync: WGA now also has the game (deduplicated by sync.php,
        // so only WGA version is passed). Kometa version should be cancelled.
        $this->storage->sync([
            [
                'id' => 'hc-kometa-brno-motor-cb-32',
                'title' => 'HC Kometa Brno - Motor CB - Winning Group Arena',
                'date' => $futureDate,
                'time' => '18:00',
                'url' => 'https://www.winninggrouparena.cz/event/hc-kometa-brno-motor-cb-32/',
            ],
        ], [$futureMonth]);

        $data = $this->storage->loadDateFile($futureDate);
        $this->assertCount(2, $data['events']);

        $eventsByStatus = [];
        foreach ($data['events'] as $event) {
            $eventsByStatus[$event['id']] = $event['status'];
        }

        // WGA version is active, old Kometa version is cancelled
        $this->assertSame('active', $eventsByStatus['hc-kometa-brno-motor-cb-32']);
        $this->assertSame('cancelled', $eventsByStatus['kometa-' . $futureDate . '-motor-cb']);
    }
}
