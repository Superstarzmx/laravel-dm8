# Laravel DM8 Driver

`duorenwei/laravel-dm8` is a Laravel 9 DM8 database driver package maintained
from the original `maxlcoder/laravel-dm8` codebase and extended with
project-level fixes.

## Included Fixes

- DSN charset handling for `pdo_dm`
- `selectOne()` compatibility for nested `rownum` queries
- query grammar adjustments used by the project
- `insertGetId()` and LOB processor fixes
- schema prefix support in published config

## Requirements

- PHP 8.0+
- Laravel 9.x database components
- DM8 PDO extension available in the runtime environment

## Installation

```bash
composer require duorenwei/laravel-dm8
```

Laravel package discovery will register these service providers automatically:

- `Duorenwei\LaravelDm8\Dm8\Dm8ServiceProvider`
- `Duorenwei\LaravelDm8\Dm8\Dm8ValidationServiceProvider`

## Local Path Development

When this package is developed inside another project repository, it can be
consumed through a Composer `path` repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/duorenwei/laravel-dm8",
      "options": {
        "symlink": false
      }
    }
  ]
}
```

Then refresh the mirrored vendor copy with:

```bash
composer update duorenwei/laravel-dm8 --with-all-dependencies --no-scripts
```

## Release Notes

Use Git tags as package versions when publishing to a VCS or Composer
repository. Do not maintain a manual `version` field in `composer.json`.
