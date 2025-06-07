# Trakli Plugin System

## Plugin Structure

Plugins should follow this directory structure:

```
plugins/
├── example/                     # Plugin directory (must match plugin ID)
│   ├── src/                     # Plugin source code
│   │   ├── Http/                # Controllers, Middleware, etc.
│   │   │   └── Controllers/     # Controller classes
│   │   ├── Models/              # Eloquent models
│   │   └── ExampleServiceProvider.php  # Service provider
│   ├── resources/               # Views, assets, translations
│   │   ├── assets/              # Frontend assets (CSS, JS, images)
│   │   └── views/              # Blade templates
│   ├── routes/                  # Route files
│   │   └── web.php              # Web routes
│   ├── composer.json            # Composer dependencies
│   └── plugin.json              # Plugin manifest
```

## Plugin Manifest (plugin.json)

Each plugin must have a `plugin.json` file in its root directory with the following structure:

```json
{
    "id": "example",
    "name": "Example Plugin",
    "description": "A brief description of what this plugin does.",
    "version": "1.0.0",
    "namespace": "Trakli\\\\ExamplePlugin",
    "provider": "Trakli\\\\ExamplePlugin\\\\ExampleServiceProvider",
    "enabled": true,
    "requires": {
        "php": ">=8.1",
        "laravel/framework": "^10.0"
    }
}
```

### Field Descriptions

- **id**: (Required) A unique identifier for your plugin (e.g., `example`). This should match the plugin's directory name exactly and use only lowercase alphanumeric characters and hyphens.
- **name**: (Required) Human-readable name of the plugin.
- **description**: Brief description of what the plugin does.
- **version**: Current version of the plugin (following [SemVer](https://semver.org/)).
- **namespace**: Base PHP namespace for the plugin's classes. Should follow the format: `Trakli\\\\{PluginName}` where PluginName is in StudlyCase.
- **provider**: Fully qualified class name of the service provider.
- **enabled**: Whether the plugin is enabled by default.
- **requires**: (Optional) PHP and package dependencies.

## Naming Conventions

### Plugin ID
- Use only lowercase alphanumeric characters and hyphens (e.g., `example`, `user-import`)
- Must match the plugin's directory name exactly
- Keep it short but descriptive
- Examples:
  - `analytics`
  - `payments`
  - `user-import`

### Namespace
- Follow PSR-4 autoloading standards
- Use the format: `Trakli\\\\{PluginName}` where PluginName is in StudlyCase
- Examples:
  - ID: `analytics` → Namespace: `Trakli\\\\Analytics`
  - ID: `user-import` → Namespace: `Trakli\\\\UserImport`

### Directory Structure
- The plugin directory name must exactly match the plugin ID
- Use `kebab-case` for file and directory names
- Follow Laravel's standard directory structure

## Best Practices

1. **Unique IDs**: Always use a unique ID for your plugin to avoid conflicts.
2. **Version Control**: Include your plugin in version control with its dependencies.
3. **Dependencies**: Clearly specify all dependencies in `composer.json`.
4. **Configuration**: Use Laravel's configuration system for plugin settings.
5. **Migrations**: Include database migrations in the `database/migrations` directory.
6. **Assets**: Publish assets using Laravel's asset publishing.
7. **Documentation**: Include a README.md in your plugin's root directory.

## Example Plugin

See the `example` directory for a complete example plugin implementation.
