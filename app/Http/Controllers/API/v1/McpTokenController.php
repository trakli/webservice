<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the Sanctum personal access tokens a user issues for connecting an
 * external AI client (Claude Desktop, Cursor) to the MCP server. Tokens created
 * here carry the "mcp" ability so they can be listed apart from session tokens.
 */
class McpTokenController extends ApiController
{
    private const ABILITY = 'mcp';

    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->latest()
            ->get()
            ->filter(fn ($token) => in_array(self::ABILITY, (array) $token->abilities, true))
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
            ])
            ->values();

        return $this->success($tokens, __('MCP tokens retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $token = $request->user()->createToken($data['name'], [self::ABILITY]);

        return $this->success([
            'id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            'token' => $token->plainTextToken,
        ], __('MCP token created'), 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = $request->user()->tokens()
            ->where('id', $id)
            ->delete();

        if (! $deleted) {
            return $this->failure(__('Token not found'), 404);
        }

        return $this->success(null, __('MCP token revoked'));
    }
}
