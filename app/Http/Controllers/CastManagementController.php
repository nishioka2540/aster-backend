<?php

namespace App\Http\Controllers;

use App\Application\CastManagement\UpdateCastEmploymentUseCase;
use App\Application\Query\CastManagementQueryService;
use App\Services\StoreAccess;
use Illuminate\Http\Request;

final class CastManagementController extends Controller
{
    public function index(Request $request, StoreAccess $access, CastManagementQueryService $query)
    {
        abort_unless($access->allows($request->user(), 'cast.manage'), 403);
        return response()->json(['data' => $query->all(), 'roles' => $query->roles()]);
    }

    public function update(Request $request, int $membershipId, StoreAccess $access, CastManagementQueryService $query, UpdateCastEmploymentUseCase $useCase)
    {
        abort_unless($access->allows($request->user(), 'cast.manage'), 403);
        $data = $request->validate(['retired' => ['required', 'boolean']]);
        return response()->json(['data' => $useCase->execute($membershipId, (bool) $data['retired'], $query)]);
    }

    public function updateRole(Request $request, int $membershipId, StoreAccess $access, CastManagementQueryService $query, \App\Application\CastManagement\UpdateCastRoleUseCase $useCase)
    {
        abort_unless($access->allows($request->user(), 'cast.manage'), 403);
        $data = $request->validate(['role_id' => ['present', 'nullable', 'integer']]);
        return response()->json(['data' => $useCase->execute($membershipId, $data['role_id'] !== null ? (int) $data['role_id'] : null, $query)]);
    }
}
