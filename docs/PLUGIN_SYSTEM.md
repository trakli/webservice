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
        "php": ">=7.4",
        "laravel/framework": "^8.0"
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

## Best Practices

- Keep plugin code isolated and self-contained
- Use a unique namespace for your plugin
- Follow PSR-4 autoloading standards
- Include proper error handling
- Document your plugin's functionality and requirements
