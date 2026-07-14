<?php

namespace App\Http\Controllers\API\v1;

use App\Contracts\Entitlements;
use App\Contracts\Integration;
use App\Contracts\IntegrationUi;
use App\Http\Controllers\API\ApiController;
use App\Services\IntegrationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Integration', description: 'Installed integrations')]
class IntegrationController extends ApiController
{
    public function __construct(private IntegrationRegistry $registry)
    {
    }

    #[OA\Get(
        path: '/integrations',
        summary: 'List installed integrations',
        tags: ['Integration'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'key', type: 'string'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'description', type: 'string', nullable: true),
                                    new OA\Property(property: 'category', type: 'string'),
                                    new OA\Property(property: 'icon', type: 'string', nullable: true),
                                    new OA\Property(property: 'feature_key', type: 'string', nullable: true),
                                    new OA\Property(property: 'configured', type: 'boolean'),
                                    new OA\Property(property: 'entitled', type: 'boolean'),
                                    new OA\Property(
                                        property: 'ui',
                                        type: 'object',
                                        nullable: true,
                                        description: 'Where and how this integration surfaces in the client. Null when the integration declares no UI.'
                                    ),
                                ],
                                type: 'object'
                            )
                        ),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $entitlements = app(Entitlements::class);

        $data = array_map(function (Integration $integration) use ($user, $entitlements) {
            $featureKey = $integration->featureKey();

            return [
                'key' => $integration->key(),
                'name' => $integration->name(),
                'description' => $integration->description(),
                'category' => $integration->category(),
                'icon' => $integration->icon(),
                'feature_key' => $featureKey,
                'configured' => $integration->isConfigured(),
                'entitled' => $featureKey === null || $entitlements->allows($user, $featureKey),
                'ui' => $integration instanceof IntegrationUi ? $integration->ui() : null,
            ];
        }, $this->registry->all());

        return $this->success($data);
    }
}
