<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'account' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $account = trim((string) $credentials['account']);
        $password = (string) $credentials['password'];

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
