<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:donation,discount'],
            'period' => ['nullable', 'in:daily,monthly,yearly'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $type = $filters['type'] ?? 'donation';
        $period = $filters['period'] ?? 'daily';
        $start = match ($period) {
            'monthly' => CarbonImmutable::createFromFormat('Y-m', $filters['month'] ?? now()->format('Y-m'))->startOfMonth(),
            'yearly' => CarbonImmutable::createFromDate((int) ($filters['year'] ?? now()->year), 1, 1)->startOfYear(),
            default => CarbonImmutable::createFromFormat('Y-m-d', $filters['date'] ?? now()->toDateString())->startOfDay(),
        };
        $end = match ($period) {
            'monthly' => $start->endOfMonth(),
            'yearly' => $start->endOfYear(),
            default => $start->endOfDay(),
        };

        $query = AgentLog::query()
            ->with(['product:id,name,sku,original_price', 'partner:id,name'])
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end]);

        if ($type === 'donation') {
            $query->where('action_type', 'donation_requested')
                ->where('payload->event_type', 'donation_completed');
        } else {
            $query->where('action_type', 'discount_applied');
        }

        $records = $query->orderBy('created_at')->get()->map(function (AgentLog $log) use ($type): array {
            $payload = $log->payload ?? [];

            return [
                'id' => $log->id,
                'occurred_at' => $log->created_at?->toIso8601String(),
                'product_name' => $log->product?->name ?? $payload['product_name'] ?? 'Produk dihapus',
                'sku' => $log->product?->sku ?? $payload['product_sku'] ?? '—',
                'batch_code' => $payload['batch_code'] ?? '—',
                'quantity' => $type === 'donation' ? (int) ($payload['quantity_donated'] ?? $payload['quantity'] ?? 0) : null,
                'unit' => $payload['unit'] ?? null,
                'partner_name' => $log->partner?->name ?? $payload['partner_name'] ?? '—',
                'discount_percentage' => $type === 'discount' ? (float) ($payload['discount_percentage'] ?? 0) : null,
                'original_price' => $type === 'discount' ? (float) ($payload['original_price'] ?? $log->product?->original_price ?? 0) : null,
                'discount_price' => $type === 'discount' ? (float) ($payload['new_discount_price'] ?? $payload['discount_price'] ?? 0) : null,
                'reasoning' => $log->reasoning,
            ];
        })->values();

        $totals = [
            'transactions' => $records->count(),
            'quantity_donated' => $type === 'donation' ? (int) $records->sum('quantity') : 0,
            'average_discount_percentage' => $type === 'discount' && $records->isNotEmpty()
                ? round((float) $records->avg('discount_percentage'), 1)
                : 0,
        ];

        return Inertia::render('Reports/Index', [
            'type' => $type,
            'period' => $period,
            'periodValue' => match ($period) {
                'monthly' => $start->format('Y-m'),
                'yearly' => $start->format('Y'),
                default => $start->format('Y-m-d'),
            },
            'periodStart' => $start->toDateString(),
            'periodEnd' => $end->toDateString(),
            'records' => $records,
            'totals' => $totals,
        ]);
    }
}
