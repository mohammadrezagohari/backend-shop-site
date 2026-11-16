<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        return response()->json(User::query()->latest('id')->paginate($perPage));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_admin' => ['required', 'boolean']]);

        if ($request->user()->is($user) && ! $data['is_admin']) {
            return response()->json(['message' => 'You cannot remove your own administrator access.'], 422);
        }

        $user->update($data);

        return response()->json(['data' => $user->refresh()]);
    }
}
