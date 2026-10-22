<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkflowIntegrationController extends Controller
{
    /**
     * Persist the aggregate metrics posted by an n8n execution.
     */
    public function storeSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'execution_id' => ['nullable', 'string', 'max:255'],
            'execution_time' => ['required', 'date'],
            'total_processed' => ['required', 'integer', 'min:0'],
            'total_discounted' => ['required', 'integer', 'min:0'],
            'total_donated' => ['required', 'integer', 'min:0'],
            'total_skipped' => ['required', 'integer', 'min:0'],
            'total_failed' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:success,failed,partial'],
        ]);

        $execution = AgentExecution::query()->create([
            'execution_id' => $validated['execution_id'] ?? null,
            'event_type' => 'summary',
            'execution_time' => $validated['execution_time'],
            'total_processed' => $validated['total_processed'],
            'total_discounted' => $validated['total_discounted'],
            'total_donated' => $validated['total_donated'],
            'total_skipped' => $validated['total_skipped'],
            'total_failed' => $validated['total_failed'] ?? 0,
            'status' => $validated['status'],
            'payload' => $validated,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Workflow execution summary recorded.',
            'data' => ['id' => $execution->id],
        ], Response::HTTP_CREATED);
    }

    /**
     * Persist an n8n Error Trigger payload for operational troubleshooting.
     */
    public function storeError(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'execution_id' => ['nullable', 'string', 'max:255'],
            'action_type' => ['required', 'string', 'in:workflow_error'],
            'status' => ['required', 'string', 'in:failed'],
            'reasoning' => ['required', 'string', 'max:10000'],
            'payload' => ['required', 'array'],
        ]);

        $execution = AgentExecution::query()->create([
            'execution_id' => $validated['execution_id'] ?? data_get($validated, 'payload.id'),
            'event_type' => 'error',
            'execution_time' => now(),
            'status' => 'failed',
            'error_message' => $validated['reasoning'],
            'payload' => $validated['payload'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Workflow execution error recorded.',
            'data' => ['id' => $execution->id],
        ], Response::HTTP_CREATED);
    }
}
