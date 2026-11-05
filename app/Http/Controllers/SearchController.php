<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Heptapod\Models\User;
use Heptapod\Queries\SearchReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class SearchController extends Controller
{
    public function __invoke(Request $request, SearchReader $reader): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        Validator::make($request->all(), ['q' => ['sometimes', 'nullable', 'string', 'max:255']])->validate();

        return response()->json($reader->read($user, trim($request->string('q')->toString()))->toArray());
    }
}
