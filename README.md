# Symfony Docs Mate Extension

Standalone Symfony AI Mate extension for efficient lookup in Symfony
documentation on Symfony 8+.

## What It Provides

This extension exposes a small set of Mate capabilities for browsing and
retrieving Symfony documentation without using embeddings or a vector store.

By default, it can manage its own local cached docs snapshot. You only need to
configure `symfony_docs_mate.docs_root` when you want to override that behavior
and point the extension at an explicit local checkout.

Repository: `Radprosium/symfony-docs-mate-extension`

### Tools

- `symfony-docs-catalog`
- `symfony-docs-search`
- `symfony-docs-page`
- `symfony-docs-section`

### Resources

- `symfony-docs://catalog`
- `symfony-docs://manifest-status`

## How to Use It in a Local Symfony Project

These steps assume your Symfony project already has `symfony/ai-mate`
installed.

### Requirements

- PHP 8.4+
- Symfony 8+
- `symfony/ai-mate` installed in the host project

### 1. Add the Extension as a Local Path Repository

From your Symfony project root:

```bash
composer config repositories.symfony-docs-mate-extension '{
  "type": "path",
  "url": "/path/to/symfony-docs-mate-extension",
  "options": { "symlink": true }
}'

composer require --dev rad/symfony-docs-mate-extension:*
```

### Install It from a Package Registry Instead of a Path Repository

If this extension is published to a package registry, use the normal Composer
flow instead:

```bash
composer require --dev rad/symfony-docs-mate-extension
```

### 2. Initialize Mate if Needed

```bash
test -d mate || vendor/bin/mate init
```

### 3. Optional: Override the Docs Source With a Local Checkout

Add these parameters to `mate/config.php`:

```php
<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('symfony_docs_mate.docs_root', '/path/to/symfony-docs')
        ->set('symfony_docs_mate.cache_dir', '%mate.root_dir%/var/cache/symfony-docs-mate')
    ;
};
```

If your project already has a custom `mate/config.php`, keep the existing file
and only add these parameter definitions.

If you do nothing, the extension will download and cache a managed docs snapshot
automatically on first use.

### 4. Optional: Pin the Managed Snapshot Ref

If you want to override the auto-detected docs branch, set:

```php
<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        ->set('symfony_docs_mate.docs_ref', '8.0')
        ->set(
            'symfony_docs_mate.archive_url',
            'https://codeload.github.com/symfony/symfony-docs/tar.gz/refs/heads/{ref}'
        )
    ;
};
```

The `{ref}` placeholder is replaced automatically.

### 5. Optional: Prefetch the Snapshot Ahead of Time

Use the sync binary when you want to warm the docs cache during setup, CI, or
container start instead of waiting for the first tool call:

```bash
vendor/bin/symfony-docs-mate-sync
```

Useful options:

```bash
vendor/bin/symfony-docs-mate-sync --force
vendor/bin/symfony-docs-mate-sync --ref=8.0
```

If you install this package as a local Composer path repository and later add or
change its binary metadata, refresh that package once so `vendor/bin/` is
rebuilt:

```bash
composer update rad/symfony-docs-mate-extension
```

## Containerized Mate Setup

With the managed snapshot mode, containers do **not** need a separate
`symfony-docs/` bind mount.

The extension downloads the docs snapshot into its own cache directory on first
use.

### 1. Ensure the Cache Directory Is Writable

Example:

```php
$container->parameters()
    ->set('symfony_docs_mate.cache_dir', '%mate.root_dir%/var/cache/symfony-docs-mate');
```

### 2. Optional: Override the Default Download Ref

```php
$container->parameters()
    ->set('symfony_docs_mate.docs_ref', '8.0');
```

### 3. Optional: Use an Explicit In-Container Checkout Instead

If you prefer an explicit checkout or pre-mounted volume, then the docs path
must be the path **inside the container**, not the host path.

```php
$container->parameters()
    ->set('symfony_docs_mate.docs_root', '/workspace/symfony-docs');
```

### 4. Refresh Mate Discovery

```bash
vendor/bin/mate discover
```

### 5. Verify That the Extension Loaded

```bash
vendor/bin/mate debug:extensions --show-all
vendor/bin/mate debug:capabilities --extension=rad/symfony-docs-mate-extension
vendor/bin/mate mcp:tools:list --extension=rad/symfony-docs-mate-extension
```

### 6. Call the Tools Directly

```bash
vendor/bin/mate mcp:tools:call symfony-docs-search '{"query":"autowiring","within":["service_container"]}'
vendor/bin/mate mcp:tools:call symfony-docs-page '{"locator":"service_container.rst"}'
vendor/bin/mate mcp:tools:call symfony-docs-section '{"locator":"service_container/autowiring.rst","maxLines":12,"maxChars":900}'
```

## Notes

- Keep the docs path generic when sharing examples or screenshots.
- The default install path is now managed snapshot mode. A separate
  `symfony-docs/` clone is optional.
- `vendor/bin/symfony-docs-mate-sync` lets you prefetch or refresh the
  managed snapshot explicitly.
- If this package is installed from a local path repository, run
  `composer update rad/symfony-docs-mate-extension` after local package metadata
  changes so Composer refreshes `vendor/bin/`.
- In containerized setups, only set `symfony_docs_mate.docs_root` if that exact
  path exists inside the container.
- The standalone repository for this package is
  `https://github.com/Radprosium/symfony-docs-mate-extension`.
- After changing this extension locally, rerun:

```bash
vendor/bin/mate discover
```

- The extension builds a lightweight cached manifest from the docs tree, then
  uses metadata-first lookup with bounded excerpt retrieval.
