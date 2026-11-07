<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemResetController extends Controller
{
    public function preview(Request $request, SystemReset $reset): JsonResponse
    {
        return response()->json(['data' => $reset->preview($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, SystemReset $reset): JsonResponse
    {
        $reset->authorize($request->user());
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', Rule::in(['RESET ALL DATA'])],
            'preview_token' => ['required', 'string', 'max:4096'],
        ]);

        return response()->json(['data' => $reset->execute($request->user(),
            $data['password'], $data['preview_token'])])
            ->header('Cache-Control', 'private, no-store');
    }
}
