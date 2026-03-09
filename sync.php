<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use RondoAkce\EventDeduplicator;
use RondoAkce\EventStorage;
use RondoAkce\IcsGenerator;
use RondoAkce\KometaScraper;
use RondoAkce\Scraper;

// Configuration
$dataDir = __DIR__ . '/data/events';
$outputPath = __DIR__ . '/output/calendar.ics';
$monthsAhead = 12;
$kometaSeasons = KometaScraper::getCurrentSeasons();

echo "Starting calendar sync...\n";

try {
    // Step 1: Scrape events from Winning Group Arena
    echo "Scraping events from Winning Group Arena...\n";
    $scraper = new Scraper();
    $result = $scraper->scrapeEvents($monthsAhead);
    $wgaEvents = $result['events'];
    $fetchedMonths = $result['fetchedMonths'];
    echo "Found " . count($wgaEvents) . " events from WGA (" . count($fetchedMonths) . " months).\n";

    // Step 2: Scrape events from HC Kometa
    echo "Scraping events from HC Kometa...\n";
    $kometaScraper = new KometaScraper();
    $kometaResult = $kometaScraper->scrapeEvents($kometaSeasons);
    $kometaEvents = $kometaResult['events'];
    echo "Found " . count($kometaEvents) . " events from HC Kometa.\n";

    // Step 3: Deduplicate (prefer WGA events when both have the same Kometa game)
    echo "Deduplicating events...\n";
    $deduplicator = new EventDeduplicator();
    $events = $deduplicator->deduplicate($wgaEvents, $kometaEvents);
    echo "Total unique events: " . count($events) . "\n";

    if (empty($events)) {
        echo "WARNING: No events found! Check if the website structure has changed.\n";
        exit(1);
    }

    // Step 4: Sync with storage (handles new/updated/cancelled events)
    // Only cancel events in months that were successfully fetched from WGA
    echo "Syncing with storage...\n";
    $storage = new EventStorage($dataDir);
    $storage->sync($events, $fetchedMonths);

    // Step 5: Generate ICS from all stored events
    echo "Generating ICS file...\n";
    $allEvents = $storage->getAllEvents();
    $generator = new IcsGenerator($outputPath);
    $generator->generate($allEvents);

    echo "Done! Generated calendar with " . count($allEvents) . " events.\n";
    echo "Output: $outputPath\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
