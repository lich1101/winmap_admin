<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandLog;
use App\Services\TerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminalController extends Controller
{
    public function run(Request $request, TerminalService $terminal): JsonResponse
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:2000'],
            'cwd' => ['nullable', 'string', 'max:2000'],
        ]);

        $log = $terminal->run($data['command'], $data['cwd'] ?? null, $request->user(), $request);

        return response()->json(['data' => $log]);
    }

    public function history(): JsonResponse
    {
        return response()->json([
            'data' => CommandLog::query()->latest()->limit(50)->get(),
        ]);
    }
}
