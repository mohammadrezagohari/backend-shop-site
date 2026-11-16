<?php

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductCommentController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->comments()->where('is_approved', true)->latest()->get()]);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'rating' => ['required', 'integer', 'between:1,5'], 'body' => ['required', 'string', 'max:2000']]);
        $comment = $product->comments()->create($data + ['user_id' => $request->user()?->id]);

        return response()->json(['data' => $comment, 'message' => 'Comment submitted for moderation.'], 201);
    }
}
