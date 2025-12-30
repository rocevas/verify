<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlacklistController extends Controller
{
    /**
     * Get all blacklist entries with pagination
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 20);
            $page = $request->input('page', 1);
            
            $query = Blacklist::query();
            
            // Apply filters if needed
            if ($request->has('type')) {
                $query->where('type', $request->input('type'));
            }
            
            if ($request->has('active')) {
                $query->where('active', $request->boolean('active'));
            }
            
            $blacklists = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
            
            return response()->json([
                'data' => $blacklists->items(),
                'pagination' => [
                    'current_page' => $blacklists->currentPage(),
                    'last_page' => $blacklists->lastPage(),
                    'per_page' => $blacklists->perPage(),
                    'total' => $blacklists->total(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('BlacklistController index error: ' . $e->getMessage());
            return response()->json([
                'error' => $e->getMessage(),
                'data' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                ],
            ], 500);
        }
    }
}

