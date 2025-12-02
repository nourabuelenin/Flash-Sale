<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\InventoryService; // Import your Service
use Illuminate\Support\Facades\Log;

class ReleaseHolds extends Command
{
    // The text you type in the terminal to run this
    protected $signature = 'holds:release';

    // A description for humans
    protected $description = 'Checks for expired holds and releases stock back to inventory';

    // The actual logic
    public function handle(InventoryService $service)
    {
        $this->info('Checking for expired holds...');

        try {
            // Call the method you wrote in InventoryService.php
            $count = $service->releaseExpiredHolds();
            
            if ($count > 0) {
                $this->info("Released {$count} expired holds.");
                Log::info("Background Job: Released {$count} items back to stock.");
            } else {
                $this->info("No expired holds found.");
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            Log::error("Background Job Failed: " . $e->getMessage());
        }
    }
}