<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebsiteProvisionRun;
use App\Services\WebsiteProvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteProvisionController extends Controller
{
    public function index(WebsiteProvisionService $service): JsonResponse
    {
        return response()->json([
            'defaults' => $service->defaultCreatePayload(),
            'data' => $service->recentRuns(),
        ]);
    }

    public function store(Request $request, WebsiteProvisionService $service): JsonResponse
    {
        $data = $request->validate([
            'subdomain' => ['required', 'string', 'max:63'],
            'parent_domain' => ['nullable', 'string', 'max:255'],
            'www_root' => ['nullable', 'string', 'max:255'],
            'system_user' => ['nullable', 'string', 'max:255'],
            'source_database' => ['nullable', 'string', 'max:255'],
            'mysql_password_file' => ['nullable', 'string', 'max:1000'],
            'ssl_registration_email' => ['nullable', 'email:rfc', 'max:255'],
            'website_username' => ['nullable', 'string', 'max:255'],
            'website_password' => ['nullable', 'string', 'max:2000'],
        ]);

        $run = $service->createRun($data, $request->user());

        return response()->json([
            'data' => $service->serializeRun($run),
        ], 201);
    }

    public function show(WebsiteProvisionRun $run, WebsiteProvisionService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->serializeRun($run),
        ]);
    }

    public function runAll(WebsiteProvisionRun $run, WebsiteProvisionService $service): JsonResponse
    {
        $run = $service->runAll($run);

        return response()->json([
            'data' => $service->serializeRun($run),
        ]);
    }

    public function runStep(WebsiteProvisionRun $run, string $step, WebsiteProvisionService $service): JsonResponse
    {
        $run = $service->runStep($run, $step);

        return response()->json([
            'data' => $service->serializeRun($run),
        ]);
    }
}
