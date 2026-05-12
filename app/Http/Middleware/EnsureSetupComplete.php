<?php

namespace App\Http\Middleware;

use App\Services\SetupConfigurationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupComplete
{
    public function __construct(
        private readonly SetupConfigurationService $setupConfiguration,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->setupConfiguration->isComplete()) {
            return response()->json([
                'message' => 'Cần hoàn tất bước setup server, multisite và credential website trước khi dùng màn quản lý.',
                'setup_required' => true,
            ], 428);
        }

        return $next($request);
    }
}
