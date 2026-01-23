<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use RondoAkce\EventStorage;
use RondoAkce\IcsGenerator;
use RondoAkce\Scraper;

// Configuration
$dataDir = __DIR__ . '/data/events';
$outputPath = __DIR__ . '/output/calendar.ics';
$monthsAhead = 12;

echo "Starting calendar sync...\n";

try {
    // Step 1: Scrape events from website
    echo "Scraping events from website...\n";
    $scraper = new Scraper();
    $result = $scraper->scrapeEvents($monthsAhead);
    $events = $result['events'];
    $fetchedMonths = $result['fetchedMonths'];
    echo "Found " . count($events) . " events from " . count($fetchedMonths) . " months.\n";

    if (empty($events)) {
        echo "WARNING: No events found! Check if the website structure has changed.\n";
        exit(1);
    }

    // Step 2: Sync with storage (handles new/updated/cancelled events)
    // Only cancel events in months that were successfully fetched
    echo "Syncing with storage...\n";
    $storage = new EventStorage($dataDir);
    $storage->sync($events, $fetchedMonths);

    // Step 3: Generate ICS from all stored events
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
