<?php

namespace App\Http\Controllers;

use App\Models\InventorySyncRun;
use Inertia\Inertia;
use Inertia\Response;

class InventorySyncHistoryController extends Controller
{
    public function index(): Response
    {
        $runs = InventorySyncRun::query()
            ->latest('started_at')
            ->paginate(12)
            ->through(fn (InventorySyncRun $run): array => [
                'id' => $run->id,
                'trigger_type' => $run->trigger_type,
                'status' => $run->status,
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'summary' => $run->summary ?? [],
                'details' => $run->details ?? [],
                'error_message' => $run->error_message,
            ]);

        return Inertia::render('Inventory/SyncHistory', ['runs' => $runs]);
    }
}
