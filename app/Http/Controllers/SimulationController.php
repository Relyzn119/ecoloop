<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgentLogResource;
use App\Models\AgentLog;
use App\Models\DiscountApproval;
use App\Models\ProductBatch;
use Inertia\Inertia;
use Inertia\Response;

class SimulationController extends Controller
{
    /**
     * Display the latest inventory batches, pending discount approvals, and agent activity.
     */
    public function index(): Response
    {
        $batches = ProductBatch::query()
            ->with(['product:id,sku,name,category_id,unit', 'product.category:id,name'])
            ->orderBy('expiration_date')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(function (ProductBatch $batch): array {
                $daysRemaining = (int) now()->startOfDay()->diffInDays($batch->expiration_date->startOfDay(), false);

                return [
                    'id' => $batch->id,
                    'product_id' => $batch->product_id,
                    'sku' => $batch->product->sku,
                    'name' => $batch->product->name,
                    'category' => $batch->product->category?->name ?? 'General',
                    'batch_code' => $batch->batch_code,
                    'quantity_received' => $batch->quantity_received,
                    'quantity_available' => $batch->quantity_available,
                    'quantity_donated' => $batch->quantity_donated,
                    'quantity_sold' => $batch->quantity_sold,
                    'unit' => $batch->product->unit,
                    'original_price' => (float) $batch->original_price,
                    'discount_price' => $batch->discount_price !== null ? (float) $batch->discount_price : null,
                    'status' => $batch->status,
                    'source' => $batch->source,
                    'expiration_date' => $batch->expiration_date->toDateString(),
                    'days_remaining' => $daysRemaining,
                ];
            });

        $pendingApprovals = DiscountApproval::query()
            ->with(['product:id,sku,name', 'batch:id,batch_code,quantity_available,expiration_date'])
            ->where('status', 'pending')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (DiscountApproval $approval): array => [
                'id' => $approval->id,
                'product_name' => $approval->product->name,
                'sku' => $approval->product->sku,
                'batch_code' => $approval->batch->batch_code,
                'quantity' => $approval->batch->quantity_available,
                'expiration_date' => $approval->batch->expiration_date->toDateString(),
                'recommended_percentage' => $approval->recommended_percentage,
                'recommended_price' => (float) $approval->recommended_price,
                'reasoning' => $approval->reasoning,
                'created_at' => $approval->created_at?->toIso8601String(),
            ]);

        $recentLogs = AgentLog::query()
            ->with(['product:id,name,sku', 'partner:id,name'])
            ->latest('id')
            ->limit(10)
            ->get();

        return Inertia::render('Simulation/Index', [
            'batches' => $batches,
            'pendingApprovals' => $pendingApprovals,
            'recentLogs' => AgentLogResource::collection($recentLogs),
            'lastUpdatedAt' => now()->toIso8601String(),
        ]);
    }
}
