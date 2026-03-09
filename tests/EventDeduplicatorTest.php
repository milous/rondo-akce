<?php

declare(strict_types=1);

namespace RondoAkce\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RondoAkce\EventDeduplicator;

final class EventDeduplicatorTest extends TestCase
{
    private EventDeduplicator $deduplicator;

    protected function setUp(): void
    {
        $this->deduplicator = new EventDeduplicator();
    }

    #[Test]
    public function keepsPrimaryKometaEventWhenBothSourcesHaveSameDate(): void
    {
        $primary = [
            [
                'id' => 'hc-kometa-brno-motor-cb-32',
                'title' => 'HC Kometa Brno - Motor CB - Winning Group Arena',
                'date' => '2026-03-09',
                'time' => '18:00',
                'url' => 'https://www.winninggrouparena.cz/event/hc-kometa-brno-motor-cb-32/',
            ],
        ];

        $secondary = [
            [
                'id' => 'kometa-2026-03-09-motor-cb',
                'title' => 'HC Kometa Brno - Banes Motor České Budějovice',
                'date' => '2026-03-09',
                'time' => '18:00',
                'url' => 'https://www.hc-kometa.cz/zapas.asp?id=10055',
            ],
        ];

        $result = $this->deduplicator->deduplicate($primary, $secondary);

        $this->assertCount(1, $result);
        $this->assertSame('hc-kometa-brno-motor-cb-32', $result[0]['id']);
    }

    #[Test]
    public function addsSecondaryKometaEventWhenNotInPrimary(): void
    {
        $primary = [
            [
                'id' => 'koncert-dracula',
                'title' => 'Dracula - muzikalova show',
                'date' => '2026-03-17',
                'time' => '19:00',
                'url' => 'https://www.winninggrouparena.cz/event/koncert-dracula/',
            ],
        ];

        $secondary = [
            [
                'id' => 'kometa-2026-03-09-motor-cb',
                'title' => 'HC Kometa Brno - Banes Motor České Budějovice',
                'date' => '2026-03-09',
                'time' => '18:00',
                'url' => 'https://www.hc-kometa.cz/zapas.asp?id=10055',
            ],
        ];

        $result = $this->deduplicator->deduplicate($primary, $secondary);

        $this->assertCount(2, $result);
        $this->assertSame('koncert-dracula', $result[0]['id']);
        $this->assertSame('kometa-2026-03-09-motor-cb', $result[1]['id']);
    }

    #[Test]
    public function nonKometaPrimaryEventsDoNotBlockSecondary(): void
    {
        $primary = [
            [
                'id' => 'koncert-xyz',
                'title' => 'Koncert XYZ',
                'date' => '2026-03-09',
                'time' => '20:00',
                'url' => 'https://www.winninggrouparena.cz/event/koncert-xyz/',
            ],
        ];

        $secondary = [
            [
                'id' => 'kometa-2026-03-09-motor-cb',
                'title' => 'HC Kometa Brno - Motor CB',
                'date' => '2026-03-09',
                'time' => '18:00',
                'url' => 'https://www.hc-kometa.cz/zapas.asp?id=10055',
            ],
        ];

        $result = $this->deduplicator->deduplicate($primary, $secondary);

        // Both should be kept - primary is not a Kometa event
        $this->assertCount(2, $result);
    }

    #[Test]
    public function handlesEmptySources(): void
    {
        $this->assertEmpty($this->deduplicator->deduplicate([], []));
        $this->assertCount(1, $this->deduplicator->deduplicate([
            ['id' => 'e1', 'title' => 'Event', 'date' => '2026-01-01', 'time' => '18:00', 'url' => 'https://example.com'],
        ], []));
        $this->assertCount(1, $this->deduplicator->deduplicate([], [
            ['id' => 'e1', 'title' => 'Event', 'date' => '2026-01-01', 'time' => '18:00', 'url' => 'https://example.com'],
        ]));
    }

    #[Test]
    public function multipleKometaGamesOnDifferentDates(): void
    {
        $primary = [
            [
                'id' => 'hc-kometa-brno-motor-cb-32',
                'title' => 'HC Kometa Brno - Motor CB',
                'date' => '2026-03-09',
                'time' => '18:00',
                'url' => 'https://www.winninggrouparena.cz/event/hc-kometa-brno-motor-cb-32/',
            ],
        ];

        $secondary = [
            [
                'id' => 'kometa-2026-03-09-motor-cb',
                'title' => 'HC Kometa Brno - Motor CB',
                'date' => '2026-03-09',
                'time' => '18:00',
                'url' => 'https://www.hc-kometa.cz/zapas.asp?id=10055',
            ],
            [
                'id' => 'kometa-2026-03-11-motor-cb',
                'title' => 'HC Kometa Brno - Motor CB',
                'date' => '2026-03-11',
                'time' => '18:00',
                'url' => 'https://www.hc-kometa.cz/zapas.asp?id=10056',
            ],
        ];

        $result = $this->deduplicator->deduplicate($primary, $secondary);

        // 2026-03-09 deduplicated (WGA kept), 2026-03-11 added from Kometa
        $this->assertCount(2, $result);
        $this->assertSame('hc-kometa-brno-motor-cb-32', $result[0]['id']);
        $this->assertSame('kometa-2026-03-11-motor-cb', $result[1]['id']);
    }
}
