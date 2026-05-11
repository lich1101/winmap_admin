<?php

namespace App\Http\Middleware;

use App\Services\DrupalAuthenticationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureAdministrator
{
    public function __construct(
        private readonly DrupalAuthenticationService $drupalAuthentication,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->auth_source === 'drupal') {
            if (! $this->drupalAuthentication->isConfigured()) {
                $this->logout($request);
                abort(503, 'Kết nối xác thực Drupal chưa được cấu hình.');
            }

            try {
                if (! $this->drupalAuthentication->synchronizeShadowUser($user)) {
                    $this->logout($request);
                    abort(403, 'Tài khoản Drupal không còn quyền administrator hoặc đã bị khóa.');
                }
                $user = $user->fresh();
                $request->setUserResolver(static fn () => $user);
            } catch (Throwable $exception) {
                report($exception);
                $this->logout($request);
                abort(503, 'Không thể kiểm tra quyền administrator từ Drupal.');
            }
        }

        if (! $user || ! $user->isAdministrator()) {
            abort(403, 'Only administrator users can access this admin backend.');
        }

        return $next($request);
    }

    private function logout(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
