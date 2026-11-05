<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\ApiController;
use App\Http\Traits\ApiQueryable;
use App\Services\ImportRuleValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Import rules', description: 'Saved Trakli import instructions and mappings')]
class ImportRuleController extends ApiController
{
    use ApiQueryable;

    #[OA\Get(path: '/import-rules', tags: ['Import rules'], responses: [new OA\Response(response: 200, description: 'Paginated rules in the standard success envelope')])]
    public function index(Request $request): JsonResponse
    {
        return $this->success($this->applyApiQuery($request, $request->user()->importRules()->orderBy('id'), false));
    }

    #[OA\Post(path: '/import-rules', tags: ['Import rules'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ImportRuleInput')), responses: [new OA\Response(response: 201, description: 'Rule created'), new OA\Response(response: 422, description: 'Invalid rule or target')])]
    public function store(Request $request, ImportRuleValidator $validator): JsonResponse
    {
        $data = $validator->validate($request->all(), $request->user());

        return $this->success($request->user()->importRules()->create($data), __('Import rule created.'), 201);
    }

    #[OA\Get(path: '/import-rules/{id}', tags: ['Import rules'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Rule details'), new OA\Response(response: 404, description: 'Rule not found')])]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->success($request->user()->importRules()->findOrFail($id));
    }

    #[OA\Patch(path: '/import-rules/{id}', tags: ['Import rules'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ImportRuleInput')), responses: [new OA\Response(response: 200, description: 'Rule updated'), new OA\Response(response: 422, description: 'Invalid rule or target')])]
    #[OA\Put(path: '/import-rules/{id}', tags: ['Import rules'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ImportRuleInput')), responses: [new OA\Response(response: 200, description: 'Rule updated')])]
    public function update(Request $request, string $id, ImportRuleValidator $validator): JsonResponse
    {
        $rule = $request->user()->importRules()->findOrFail($id);
        $data = $validator->validate(array_replace($rule->attributesToArray(), $request->all()), $request->user());
        $rule->update($data);

        return $this->success($rule, __('Import rule updated.'));
    }

    #[OA\Delete(path: '/import-rules/{id}', tags: ['Import rules'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Rule deleted'), new OA\Response(response: 404, description: 'Rule not found')])]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->importRules()->findOrFail($id)->delete();

        return $this->success(null, __('Import rule deleted.'));
    }
}
