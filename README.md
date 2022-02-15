# PHPStan Blade

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
