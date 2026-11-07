<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardOverview;
use App\Services\FoundationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardOverview $overview): JsonResponse
    {
        FoundationAccess::permit($request->user(), 'dashboard.view');
        $input = $request->validate([
            'days' => ['sometimes', 'required', 'integer', Rule::in([7, 30, 90])],
        ]);

        return response()->json([
            'data' => $overview->read($request->user(), (int) ($input['days'] ?? 30)),
        ])->header('Cache-Control', 'private, no-store');
    }
}
