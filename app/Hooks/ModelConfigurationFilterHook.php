<?php

namespace App\Hooks;

use App\Http\Traits\ApiQueryable;
use Illuminate\Http\Request;
use Whilesmart\ModelConfiguration\Enums\ConfigAction;
use Whilesmart\ModelConfiguration\Interfaces\ModelHookInterface;

class ModelConfigurationFilterHook implements ModelHookInterface
{
    use ApiQueryable;

    public function beforeQuery(mixed $data, ConfigAction $action, Request $request): mixed
    {
        if ($action == ConfigAction::INDEX) {
            return $this->applyApiQuery($request, $data, false);
        }

        return $data;
    }

    public function afterQuery(mixed $results, ConfigAction $action, Request $request): mixed
    {
        if ($action == ConfigAction::UPDATE) {
            $this->updateClientId($results, $request);
            $results->refresh();
        }

        if ($action == ConfigAction::STORE) {
            $user = $request->user();
            if (isset($request['client_id'])) {
                $results->setClientGeneratedId($request['client_id'], $user);
            }
            $results->markAsSynced();

            $results->refresh();
        }

        return $results;
    }
}
