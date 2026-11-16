<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Models\SiteBadge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SiteBadgeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => SiteBadge::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function adminIndex(): JsonResponse
    {
        return response()->json(['data' => SiteBadge::query()->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => SiteBadge::create($this->data($request))], 201);
    }

    public function update(Request $request, SiteBadge $badge): JsonResponse
    {
        $badge->update($this->data($request));

        return response()->json(['data' => $badge->fresh()]);
    }

    public function destroy(SiteBadge $badge): JsonResponse
    {
        $badge->delete();

        return response()->json(status: 204);
    }

    private function data(Request $request): array
    {
        // Accept linked images and certificate verification links, not executable embed HTML.
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'image_url' => ['required', 'url:http,https', 'max:2048'],
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
