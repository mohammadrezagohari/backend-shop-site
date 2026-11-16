<?php

namespace App\Modules\Content\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Models\ProductComment;
use App\Modules\Content\Domain\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\TagRules;

class AdminContentController extends Controller
{
    public function posts(Request $request): JsonResponse
    {
        return response()->json(Post::query()->latest()->paginate($request->integer('per_page', 20)));
    }

    public function storePost(Request $request): JsonResponse
    {
        $post = Post::create($this->postData($request));

        return response()->json(['data' => $post], 201);
    }

    public function updatePost(Request $request, Post $post): JsonResponse
    {
        $post->update($this->postData($request, $post));

        return response()->json(['data' => $post->fresh()]);
    }

    public function destroyPost(Post $post): JsonResponse
    {
        $post->delete();

        return response()->json(null, 204);
    }

    public function comments(Request $request): JsonResponse
    {
        $comments = ProductComment::query()
            ->with('product:id,name,slug')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($comments);
    }

    public function updateComment(Request $request, ProductComment $comment): JsonResponse
    {
        $comment->update($request->validate(['is_approved' => ['required', 'boolean']]));

        return response()->json(['data' => $comment->fresh()->load('product:id,name,slug')]);
    }

    public function destroyComment(ProductComment $comment): JsonResponse
    {
        $comment->delete();

        return response()->json(null, 204);
    }

    private function postData(Request $request, ?Post $post = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:255', Rule::unique('posts')->ignore($post)],
            'excerpt' => ['required', 'string'],
            'body' => ['required', 'string'],
            'image' => ['nullable', 'string', 'max:2048'],
            'published_at' => ['nullable', 'date'],
            ...TagRules::rules(),
        ]);
        if (array_key_exists('tags', $data) || ! $post) {
            $data['tags'] = ($data['tags'] ?? []) ?: [mb_substr($data['title'], 0, 80)];
        }

        return $data;
    }
}
