<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FoundationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/login — exchange username/password for a Bearer token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            return response()->json([
                'message' => 'Invalid username or password.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'This account is inactive.',
            ], 403);
        }

        $plain = DB::transaction(function () use ($user) {
            $token = $user->createToken('web', ['*'], now()->addHours(12))->plainTextToken;
            $user->forceFill(['last_login_at' => now()])->save();
            AuditLogger::log('login', 'users', $user->id, null, ['username' => $user->username]);

            return $token;
        });

        return response()->json([
            'token' => $plain,
            'user' => $user->only(['id', 'name', 'username', 'locale']),
        ]);
    }

    /**
     * POST /api/logout — revoke the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user && $token) {
            $token->delete();
        }

        AuditLogger::log('logout', 'users', $user?->id);

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * GET /api/me — the authenticated user's profile and roles.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'locale' => $user->locale,
                'roles' => $user->roles()->pluck('name'),
                'permissions' => $user->permissionCodes(),
                'is_administrator' => FoundationAccess::administrator($user),
                'branch_ids' => FoundationAccess::branchIds($user),
                'facility_ids' => FoundationAccess::facilityIds($user),
            ],
        ]);
    }

    public function locale(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:en,fa']]);
        DB::transaction(function () use ($request, $data) {
            $user = $request->user();
            $old = ['locale' => $user->locale];
            $user->update($data);
            AuditLogger::log('update', 'users', $user->id, $old, $data);
        });

        return $this->me($request);
    }
}
