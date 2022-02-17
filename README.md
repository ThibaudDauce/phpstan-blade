# PHPStan Blade

This PHPStan extension analyse Blade views for errors.

![Result example](https://github.com/ThibaudDauce/phpstan-blade/blob/master/docs/result.png?raw=true)

## Installation

Install the extension with Composer.

```bash
composer require thibaud-dauce/phpstan-blade
```

Add the extension config file to your `phpstan.neon`:

```neon
includes:
    - ./phpstan-baseline.neon
    - ./vendor/nunomaduro/larastan/extension.neon
    - ./vendor/thibaud-dauce/phpstan-blade/extension.neon

parameters:
    …
```

Add this Composer script to your `composer.json`:

```json
{
    "scripts": {
        "post-root-package-install": [
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
        ],
        "post-create-project-cmd": [
            "@php artisan key:generate"
        ],
        "post-autoload-dump": [
            "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
            "@php artisan package:discover"
        ],
        "phpstan": [
            "@php artisan phpstan-blade:touch-cache",
            "./vendor/bin/phpstan analyse --error-format blade"
        ]
    },
}
```

Then, you can run `composer phpstan` to touch the cache (see `TouchCacheCommand` comment if you want to know more about why it's required), and run the analyse with the Blade formatter (the Blade formatter is required to allow showing the stacktrace of the views' includes).

## Features

- [x] **Analyse `view()` calls**
- [x] **Support Blade directives**
- [x] **Support views namespaces**
- [x] **Support Livewire components**
- [x] **Support `@include` with full stacktrace showing exactly the place and the context of the error**
- [ ] Support mailable views
- [ ] Support `compact()` function for view parameters

## Limitations

### @var docblocks inside Blade views

If you want to add docblocks to your views to add type information like:

```blade
@php
    /** @var string */
    $name = config('app.name');
@endphp

{{ $name }}
```

You need to specify the variable name inside the docblock because we add docblocks too between lines so your docblocks will not be right above the assignation.
```blade
@php
    /** @var string $name */
    $name = config('app.name');
@endphp

{{ $name }}
```

### Constant types

Right now, if you pass `true` to a view, the type is generalize to `bool` to avoid errors like "If condition is always true.". It could be nice to have a way to raise an error if all `view()` calls pass `true` that the condition is always true. But I think it's hard to implement.

If you pass `['something' => null]` to a view, I transform the type to `mixed` so you may have some errors. You should specify the type for the null value so I can put the correct type information.

```php
/** @var ?User */
$user = null;

return view('user', [
    'user' => $user,
]);
```

## Development

The extension code has a lot of comment to explain every thing it's doing. The main entry point is the `ViewFunctionRule` class.