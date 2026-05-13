<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonitoredWebsite;
use App\Models\WebsiteDeletionRun;
use App\Services\WebsiteDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteDeletionController extends Controller
{
    public function store(MonitoredWebsite $website, Request $request, WebsiteDeletionService $service): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:255'],
            'run_now' => ['boolean'],
        ]);

        $run = $service->createRun($website, $request->user(), (string) $data['confirmation']);
        if ($request->boolean('run_now')) {
            $run = $service->runAll($run);
        }

        return response()->json([
            'data' => $service->serializeRun($run),
        ], 201);
    }

    public function runAll(WebsiteDeletionRun $run, WebsiteDeletionService $service): JsonResponse
    {
        $run = $service->runAll($run);

        return response()->json([
            'data' => $service->serializeRun($run),
        ]);
    }

    public function runStep(WebsiteDeletionRun $run, string $step, WebsiteDeletionService $service): JsonResponse
    {
        $run = $service->runStep($run, $step);

        return response()->json([
            'data' => $service->serializeRun($run),
        ]);
    }
}
