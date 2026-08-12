# Plugin System Architecture

This document describes the plugin system for the Trakli webservice, which allows for modular extension of functionality.

## Overview

The plugin system allows developers to create and manage independent modules that can extend the functionality of the Trakli webservice without modifying the core codebase.

## Plugin Structure

A typical plugin has the following structure:

```
plugin-name/
├── plugin.json           # Plugin manifest
├── src/                  # PHP source files
│   ├── PluginServiceProvider.php
│   └── ...
├── routes/               # Route definitions
│   └── web.php
├── resources/
│   ├── views/           # Blade views
│   └── assets/          # Public assets (JS, CSS, images)
└── database/
    └── migrations/      # Database migrations
```

## Plugin Manifest (plugin.json)

Each plugin must have a `plugin.json` file in its root directory with the following structure:

```json
{
    "name": "Plugin Name",
    "description": "Plugin description",
    "version": "1.0.0",
    "provider": "Namespace\\To\\ServiceProvider",
    "enabled": true,
    "requires": {
        "php": ">=8.2",
        "laravel/framework": "^11.0"
    }
}
```

## Plugin Service Provider

Each plugin must have a service provider that extends Laravel's base `ServiceProvider` class:

```php
<?php

namespace YourPluginNamespace;

use Illuminate\Support\ServiceProvider;

class YourPluginServiceProvider extends ServiceProvider
{
    protected $pluginName = 'your-plugin';
    
    public function boot()
    {
        // Your boot logic here
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', $this->pluginName);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
    
    public function register()
    {
        // Your registration logic here
        // Register bindings, merge configs, etc.
    }
}
```

## Managing Plugins

Use the following Artisan commands to manage plugins:

```bash
# List all plugins
php artisan plugin list

# Discover new plugins
php artisan plugin discover

# Enable a plugin
php artisan plugin enable plugin-name

# Disable a plugin
php artisan plugin disable plugin-name
```

## Creating a New Plugin

1. Create a new directory in the `plugins/` directory
2. Create a `plugin.json` file with the required metadata
3. Create your service provider in `src/`
4. Add your routes, views, and other resources
5. Run `php artisan plugin discover` to register your plugin

## Core Extension Points

Beyond routes and migrations, the core exposes contracts a plugin can hook into. Resolve them from the container inside your service provider's `boot()`.

### Feature gating (Entitlements)

`App\Contracts\Entitlements` decides whether an owner may use a feature, what limits apply, and how much of a metered allowance remains. It is keyed on the resource owner (a user today, a shared owner later), not the user directly. The core binds a permissive default that allows everything; a plugin may rebind it to enforce its own policy.

Gate a route or action:

```php
if (! app(\App\Contracts\Entitlements::class)->allows($owner, 'your-feature')) {
    abort(403);
}
```

Feature keys are plain strings; whatever rebinds the contract decides what they mean. With no override, the default allows everything.

### Integration registry

Register a descriptor so the integration appears in `GET /api/v1/integrations`:

```php
app(\App\Services\IntegrationRegistry::class)->register($yourIntegration);
```

where `$yourIntegration` implements `App\Contracts\Integration` (`key`, `name`, `category`, `featureKey`, `isConfigured`, and so on). This layer is metadata only; connect and sync behaviour stays in the plugin.

### Document processors

Handle a document type, or take precedence over the configured engine, by registering an `App\Contracts\DocumentProcessor` with a priority:

```php
app(\App\Services\DocumentProcessorManager::class)->register($yourProcessor, priority: 100);
```

The highest-priority processor whose `supports($mimeType, $extension)` returns true wins; equal priority keeps registration order.

## Closed-Source Plugins

A plugin can live in its own repository and never ship in the open image. A build in the repository that owns it stacks it onto the public base image, using `docker/hosted/Dockerfile` here, to produce a hosted image with the plugin enabled and cached, and rebuilds whenever this repository publishes a new base. Gate its features through `Entitlements` so the same code path stays inert on the open core.

## Best Practices

- Keep plugin code isolated and self-contained
- Use a unique namespace for your plugin
- Follow PSR-4 autoloading standards
- Include proper error handling
- Document your plugin's functionality and requirements
