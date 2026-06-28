<?php

namespace App\Contracts;

/**
 * Optional capability an Integration can implement to describe where and how
 * its UI surfaces in the client, without the client knowing the vendor. The
 * descriptor is additive metadata: integrations that do not implement this
 * still list normally, just without a `ui` block.
 *
 * Shape of the returned array:
 *   [
 *     'slots'      => string[],            // named client slots, e.g. ['settings.integrations']
 *     'card'       => array|null,          // ['title' => ..., 'cta' => ..., 'description' => ...]
 *     'connect'    => array|null,          // ['type' => ..., ...type-specific keys]
 *     'onboarding' => array|null,          // ['step' => ..., 'optional' => bool, 'order' => int]
 *     'component'  => string|null,         // slot-key a plugin Nuxt layer registered, or null
 *     'show_when_unconfigured' => bool,    // optional; render with a needs-setup state instead of hiding when not configured
 *   ]
 */
interface IntegrationUi extends Integration
{
    /**
     * @return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.ShortMethodName)
     */
    public function ui(): array;
}
