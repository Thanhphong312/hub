<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * @group Auth
 *
 * Đăng nhập, đăng xuất, đổi mật khẩu, profile.
 */
class AuthController extends Controller
{
    /**
     * Login (Sanctum)
     *
     * Trả về `token` Sanctum + thông tin user (role + permissions + tier).
     * Mọi route khác (trừ /fulfill + /catalog) cần header
     * `Authorization: Bearer <token>`.
     *
     * @unauthenticated
     * @bodyParam email string required Email tài khoản. Example: admin@bullstart.com
     * @bodyParam password string required Mật khẩu. Example: password
     *
     * @response 200 {
     *   "token": "1|abcdef...",
     *   "user": {
     *     "id": 1,
     *     "name": "Admin",
     *     "email": "admin@bullstart.com",
     *     "role": {"slug": "admin", "name": "Admin", "permissions": []},
     *     "tier": {"id": 1, "name": "Tier 1"},
     *     "wallet": "1000.00"
     *   }
     * }
     * @response 422 scenario="bad credentials" {
     *   "message": "The provided credentials are incorrect.",
     *   "errors": {"email": ["The provided credentials are incorrect."]}
     * }
     * @response 403 scenario="account disabled" {"message": "Account is inactive"}
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::with('role.permissions', 'tier')->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 1) {
            return response()->json(['message' => 'Account is inactive'], 403);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'All sessions logged out']);
    }

    /**
     * Get current user
     *
     * Trả về user + role/permissions/tier + wallet hiện tại.
     *
     * @authenticated
     * @response 200 {
     *   "user": {"id": 1, "name": "Admin", "email": "admin@bullstart.com", "role": {}, "tier": {}},
     *   "wallet": "1000.00"
     * }
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('role.permissions', 'tier');
        return response()->json([
            'user' => $user,
            'wallet' => $user->wallet,
        ]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $request->new_password]);
        return response()->json(['message' => 'Password changed']);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'nullable|string',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
        ]);

        $user->update($request->only('name', 'email'));
        return response()->json(['message' => 'Profile updated', 'user' => $user->fresh()]);
    }

    public function myApiKey(Request $request)
    {
        return response()->json(['api_key' => $request->user()->api_key]);
    }

    public function regenerateMyApiKey(Request $request)
    {
        $user = $request->user();
        $user->update(['api_key' => 'bsk_' . \Illuminate\Support\Str::random(32)]);
        return response()->json(['message' => 'API key regenerated', 'api_key' => $user->fresh()->api_key]);
    }
}
