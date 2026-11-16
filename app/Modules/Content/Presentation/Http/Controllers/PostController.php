<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Domain\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['tag' => ['nullable', 'string', 'max:80']]);

        return response()->json(Post::query()->whereNotNull('published_at')->where('published_at', '<=', now())
            ->when($filters['tag'] ?? null, fn ($q, $tag) => $q->whereJsonContains('tags', $tag))->latest('published_at')->paginate(12));
    }

    public function show(Post $post): JsonResponse
    {
        abort_if(! $post->published_at || $post->published_at->isFuture(), 404);
        $post->increment('views_count');

        return response()->json(['data' => $post]);
    }
}
