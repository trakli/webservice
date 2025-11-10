<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Traits\ApiQueryable;
use App\Rules\ValidateClientId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;
use Whilesmart\ModelConfiguration\Http\Controllers\ConfigurationController as WsConfigController;

class ConfigurationController extends WsConfigController
{
    use ApiQueryable;

    // retrieve allowed configuration keys
    private function getAllowedConfigKeys(): array
    {
        return config('allowed-config-keys.keys', []);
    }

    public function store(Request $request): JsonResponse
    {
        $allowedKeys = $this->getAllowedConfigKeys();
        $validator = Validator::make($request->all(), [
            'key' => 'required|in:'.implode(',', $allowedKeys),
            'value' => 'required',
            'type' => 'required|in:string,int,float,bool,array,json,date',
            'client_id' => ['nullable', 'string', new ValidateClientId],
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        $data = $validator->validated();
        $user = $request->user();
        $formattedKey = $this->sanitizeKey($data['key']);
        $configuration_type = ConfigValueType::from($data['type']);
        $value = $configuration_type->getValue($data['value']);

        $config = $user->setConfigValue($formattedKey, $value, $configuration_type);
        if (isset($data['client_id'])) {
            $config->setClientGeneratedId($data['client_id'], $user);
        }

        $config->markAsSynced();

        return $this->success($config, 'Configuration added successfully', 201);
    }

    public function update(Request $request, $key): JsonResponse
    {
        $allowedKeys = $this->getAllowedConfigKeys();
        if (! in_array($key, $allowedKeys)) {
            return $this->failure('Invalid configuration key.', 422);
        }

        $validator = Validator::make($request->all(), [
            'value' => 'required',
            'type' => 'required|in:string,int,float,bool,array,json,date',
            'client_id' => ['nullable', 'string', new ValidateClientId],
        ]);

        if ($validator->fails()) {
            return $this->failure('Validation failed.', 422, [$validator->errors()]);
        }

        $data = $validator->validated();
        $user = $request->user();

        $formattedKey = $this->sanitizeKey($key);

        // Check if the configuration exists
        $configuration = $user->getConfig($formattedKey);

        if (! $configuration) {
            return $this->failure('Configuration not found.', 404);
        }

        // Update the configuration
        $configuration_type = ConfigValueType::from($data['type']);
        $value = $configuration_type->getValue($data['value']);
        $config = $user->setConfigValue($formattedKey, $value, $configuration_type);

        $config->markAsSynced();
        if (isset($data['client_id']) && ! $config->client_id) {
            $config->setClientGeneratedId($data['client_id'], $user);
        }

        return $this->success($config, 'Configuration updated successfully');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->configurations();

        try {
            $data = $this->applyApiQuery($request, $query, false);

            return $this->success($data, 'Configurations retrieved successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->failure($e->getMessage(), 422);
        }
    }
}
