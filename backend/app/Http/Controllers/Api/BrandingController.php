<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BrandingImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
    public function index(BrandingImages $branding): JsonResponse
    {
        return response()->json(['data' => $branding->all()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $slot, BrandingImages $branding): JsonResponse
    {
        BrandingImages::slot($slot);
        $branding->authorize($request->user());
        $data = $request->validate(['image' => ['required', 'file', 'max:5120']]);

        return response()->json(['data' => $branding->replace($request->user(), $slot, $data['image'])]);
    }

    public function destroy(Request $request, string $slot, BrandingImages $branding): JsonResponse
    {
        return response()->json(['data' => $branding->restore($request->user(), $slot)]);
    }

    // Branding artwork is public; no customer, employee, or inventory images use this route.
    public function image(string $slot, string $version, BrandingImages $branding): BinaryFileResponse
    {
        $row = $branding->image($slot, $version);

        return response()->file(Storage::disk('local')->path($row->storage_path), [
            'Content-Type' => $row->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
