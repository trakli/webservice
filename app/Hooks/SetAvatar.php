<?php

namespace App\Hooks;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;
use Whilesmart\UserAuthentication\Enums\HookAction;
use Whilesmart\UserAuthentication\Interfaces\MiddlewareHookInterface;

class SetAvatar implements MiddlewareHookInterface
{
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function before(Request $request, string $action): ?Request
    {
        return $request;
    }

    public function after(Request $request, JsonResponse $response, string $action): JsonResponse
    {
        if ($action !== HookAction::OAUTH_CALLBACK->value) {
            return $response;
        }

        $data = $response->getData(true);

        if (isset($data['user']['id'])) {
            $userId = $data['user']['id'];
            $user = User::find($userId);

            if ($user && ! $user->getConfigValue('avatar')) {
                // Get the social user from the current OAuth flow
                $driver = $request->route('driver');
                $socialUser = Socialite::driver($driver)->stateless()->user();

                $avatarUrl = null;

                if ($socialUser) {
                    switch ($driver) {
                        case 'google':
                            // Google provides avatar in the 'picture' property
                            $avatarUrl = $socialUser->user['picture'] ?? $socialUser->picture ?? null;
                            break;

                        case 'apple':
                            // Apple typically doesn't provide avatar URLs due to privacy
                            // But we'll check if available
                            $avatarUrl = $socialUser->avatar ?? null;
                            break;

                        default:
                            // Fallback for other providers
                            $avatarUrl = $socialUser->getAvatar() ?? $socialUser->avatar ?? null;
                            break;
                    }
                }

                if ($avatarUrl) {
                    $user->setConfigValue('avatar', $avatarUrl, ConfigValueType::String);
                    $data['user'] = $user->fresh()->toArray();
                    $response->setData($data);
                }
            }
        }

        return $response;
    }
}
