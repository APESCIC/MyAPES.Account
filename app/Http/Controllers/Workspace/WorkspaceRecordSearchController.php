<?php

namespace App\Http\Controllers\Workspace;

use App\Core\Accounts\User;
use App\Core\Search\RecordSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Debounced JSON endpoint for Ctrl+K record results (#528).
 */
final class WorkspaceRecordSearchController extends Controller
{
    public function __invoke(Request $request, RecordSearch $search): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        if (mb_strlen($query) < 2) {
            return response()->json(['groups' => []]);
        }

        $status = isset($validated['status']) ? trim((string) $validated['status']) : null;
        if ($status === '') {
            $status = null;
        }

        return response()->json(
            $search->search($user, $query, $status),
        );
    }
}
