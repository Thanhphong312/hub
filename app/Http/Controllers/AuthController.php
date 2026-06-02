<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/login",
     *     tags={"Auth"},
     *     summary="Login (Sanctum)",
     *     description="Trả về token Sanctum + thông tin user. Mọi route khác (trừ /fulfill + /catalog) cần header `Authorization: Bearer <token>`.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="seller@bullstart.com"),
     *             @OA\Property(property="password", type="string", example="password")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200, description="OK",
     *         @OA\JsonContent(
     *             @OA\Property(property="token", type="string", example="1|abcdef..."),
     *             @OA\Property(property="user", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Bad credentials"),
     *     @OA\Response(response=403, description="Account inactive")
     * )
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
     * @OA\Get(
     *     path="/me",
     *     tags={"Auth"},
     *     summary="Get current user",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
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
            'name'     => 'nullable|string',
            'email'    => 'nullable|email|unique:users,email,' . $user->id,
            'auto_pay' => 'sometimes|boolean',
        ]);

        $user->update($request->only('name', 'email', 'auto_pay'));
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
