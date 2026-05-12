<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Controllers\Controller;
use App\Services\DrupalAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly DrupalAuthenticationService $drupalAuthentication,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        if ($request->filled('email') && ! $request->filled('account')) {
            $request->merge(['account' => $request->input('email')]);
        }

        $credentials = $request->validate([
            'account' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $account = trim((string) $credentials['account']);
        $password = (string) $credentials['password'];

        if ($this->drupalAuthentication->isConfigured()) {
            try {
                $identity = $this->drupalAuthentication->authenticateAdministrator($account, $password);
            } catch (Throwable $exception) {
                report($exception);
                abort(503, 'Không thể kết nối hệ thống xác thực Drupal.');
            }

            if (! $identity) {
                throw ValidationException::withMessages([
                    'account' => 'Tài khoản Drupal không đúng, không hoạt động, hoặc chưa có quyền administrator.',
                ]);
            }

            $user = $this->drupalAuthentication->upsertShadowAdministrator($identity);
            Auth::login($user, true);
            $request->session()->regenerate();

            return response()->json(['user' => $request->user() ?: $user]);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($account)])
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($account)])
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'account' => 'Tài khoản hoặc mật khẩu không đúng.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $user = $request->user() ?: $user;

        if (! $user || ! $user->isAdministrator()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'Tài khoản phải là administrator và đang hoạt động.');
        }

        return response()->json(['user' => $user]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'ok']);
    }
}
