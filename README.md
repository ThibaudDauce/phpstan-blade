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

## Features

- [x] **Analyse `view()` calls**
- [x] **Support Blade directives**
- [x] **Support views namespaces**
- [x] **Support Livewire components**
- [x] **Support `@include` with full stacktrace showing exactly the place and the context of the error**
- [ ] Support mailable views
- [ ] Support `compact()` function for view parameters

## Development

The extension code has a lot of comment to explain every thing it's doing. The main entry point is the `BladeRule` class.