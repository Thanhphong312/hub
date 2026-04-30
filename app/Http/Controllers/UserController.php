<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['role', 'tier']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->has('tier_id')) {
            $query->where('tier_id', $request->tier_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 20));
        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'role_id' => 'required|exists:roles,id',
            'tier_id' => 'nullable|exists:tiers,id',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role_id' => $request->role_id,
            'tier_id' => $request->tier_id,
            'api_key' => 'bsk_' . Str::random(32),
        ]);

        return response()->json(['message' => 'User created', 'user' => $user->load('role', 'tier')], 201);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'nullable|string',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role_id' => 'nullable|exists:roles,id',
            'tier_id' => 'nullable|exists:tiers,id',
            'status' => 'nullable|integer|in:0,1',
            'wallet' => 'nullable|numeric',
            'convert' => 'nullable|boolean',
        ]);

        $data = $request->only('name', 'email', 'role_id', 'tier_id', 'status', 'wallet', 'convert');

        if ($request->has('password') && $request->password) {
            $data['password'] = $request->password;
        }

        $user->update($data);
        return response()->json(['message' => 'User updated', 'user' => $user->load('role', 'tier')]);
    }

    public function show(User $user)
    {
        return response()->json([
            'user' => $user->load(['role.permissions', 'tier']),
            'order_count' => $user->orders()->count(),
            'total_spent' => abs($user->walletTransactions()->where('type', 'paid')->sum('amount')),
        ]);
    }

    public function regenerateApiKey(User $user)
    {
        $user->update(['api_key' => 'bsk_' . Str::random(32)]);
        return response()->json(['message' => 'API key regenerated', 'api_key' => $user->api_key]);
    }

    public function getApiKey(User $user)
    {
        return response()->json(['api_key' => $user->api_key]);
    }

    public function destroy(User $user)
    {
        if ($user->orders()->exists()) {
            return response()->json(['message' => 'Cannot delete user with orders'], 400);
        }
        $user->delete();
        return response()->json(['message' => 'User deleted']);
    }
}
