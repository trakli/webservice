<?php

declare(strict_types=1);

namespace App\Mcp\Http\Controllers;

use App\Mcp\Plugins\McpPluginManager;
use App\Mcp\Server\TrakliMcpServer;
use App\Mcp\Transport\SseTransport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Routing\Controller;
use Laravel\Mcp\Enums\ProtocolVersion;

class McpController extends Controller
{
    public function handle(Request $request): Response
    {
        $protocolVersion = $request->header('MCP-Protocol-Version');

        if ($protocolVersion && ! in_array($protocolVersion, ProtocolVersion::supported())) {
            return response('Unsupported protocol version', 400)
                ->header('Content-Type', 'text/plain');
        }

        $sessionId = $request->input('sessionId')
            ?? $request->header('MCP-Session-Id')
            ?? (string) \Illuminate\Support\Str::uuid();

        $transport = new SseTransport(
            request: $request,
            sessionId: $sessionId,
        );

        $server = new TrakliMcpServer($transport);
        
        // Merge plugin capabilities
        $pluginManager = app(McpPluginManager::class);
        $server->mergePluginCapabilities($pluginManager);

        $server->start();

        return $transport->run();
    }

    public function initialize(Request $request): Response
    {
        $protocolVersion = $request->header('MCP-Protocol-Version', ProtocolVersion::V2025_06_18->value);

        if (! in_array($protocolVersion, ProtocolVersion::supported())) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Unsupported protocol version',
                ],
                'id' => $request->input('id'),
            ], 400);
        }

        return $this->handle($request);
    }

    /**
     * Inspect endpoint - returns all registered MCP capabilities.
     * Used for debugging and documentation generation.
     */
    public function inspect(Request $request): Response
    {
        $pluginManager = app(McpPluginManager::class);

        $server = new TrakliMcpServer(new SseTransport(
            request: $request,
            sessionId: 'inspect',
        ));
        $server->mergePluginCapabilities($pluginManager);

        return response()->json([
            'server' => [
                'name' => $server->getName(),
                'version' => $server->getVersion(),
                'protocol_version' => $server->getSupportedProtocolVersion(),
                'instructions' => $server->getInstructions(),
            ],
            'capabilities' => $server->getCapabilities(),
            'tools' => array_map(fn ($class) => $this->getToolInfo($class), $server->getTools()),
            'resources' => array_map(fn ($class) => $this->getResourceInfo($class), $server->getResources()),
            'prompts' => array_map(fn ($class) => $this->getPromptInfo($class), $server->getPrompts()),
            'plugins' => array_map(
                fn ($slug, $metadata) => [
                    'slug' => $slug,
                    'name' => $metadata->name,
                    'version' => $metadata->version,
                    'description' => $metadata->description,
                    'dependencies' => $metadata->dependencies,
                    'permissions' => $metadata->permissions,
                ],
                array_keys($pluginManager->getRegisteredPlugins()),
                array_values($pluginManager->getRegisteredPlugins())
            ),
        ]);
    }

    /**
     * Get tool info for inspection.
     */
    private function getToolInfo(string $class): array
    {
        try {
            $tool = app($class);
            $arr = $tool->toArray();
            return [
                'class' => $class,
                'name' => $arr['name'] ?? $tool->name(),
                'description' => $arr['description'] ?? $tool->description(),
                'input_schema' => $arr['inputSchema'] ?? [],
                'output_schema' => $arr['outputSchema'] ?? [],
            ];
        } catch (\Throwable $e) {
            return [
                'class' => $class,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get resource info for inspection.
     */
    private function getResourceInfo(string $class): array
    {
        try {
            $resource = app($class);
            return [
                'class' => $class,
                'name' => $resource->name(),
                'title' => $resource->title(),
                'description' => $resource->description(),
                'mime_type' => $resource->mimeType(),
                'uri_template' => $resource instanceof \Laravel\Mcp\Server\Contracts\HasUriTemplate
                    ? (string) $resource->uriTemplate()
                    : $resource->uri(),
            ];
        } catch (\Throwable $e) {
            return [
                'class' => $class,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get prompt info for inspection.
     */
    private function getPromptInfo(string $class): array
    {
        try {
            $prompt = app($class);
            return [
                'class' => $class,
                'name' => $prompt->name(),
                'title' => $prompt->title(),
                'description' => $prompt->description(),
                'arguments' => array_map(
                    fn ($arg) => [
                        'name' => $arg->name,
                        'description' => $arg->description,
                        'required' => $arg->required,
                    ],
                    $prompt->arguments()
                ),
            ];
        } catch (\Throwable $e) {
            return [
                'class' => $class,
                'error' => $e->getMessage(),
            ];
        }
    }
}