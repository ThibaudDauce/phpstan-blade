<?php

namespace ThibaudDauce\PHPStanBlade;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Analyser\Scope;
use Illuminate\View\Factory as ViewFactory;
use PHPStan\Type\ObjectType;
use PhpParser\Node\Identifier;
use PhpParser\Node\Expr\MethodCall;
use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use PhpParser\Node\VariadicPlaceholder;

/**
 * The goal of this rule is to match the `$view_factory->make()` method call.
 * It can happen in PHP code but it's often only for `@include` (Blade replaces `@include`
 * by a call to `$__env->make(…)->render()` where `$__env` is the `ViewFactory`).
 * 
 * @implements Rule<MethodCall>
 */
class ViewFactoryMakeMethodRule implements Rule
{
    public function __construct(
        private BladeAnalyser $blade_analyser,
    ) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @inheritDoc */
    public function processNode(Node $method_call, Scope $scope): array
    {
        /**
         * This condition is not require to work because we specify `MethodCall` in `getNodeType()` above
         * but it helps VSCode to understand and provide auto-completion below.
         */
        if (! $method_call instanceof MethodCall) return [];

        /**
         * We only want to check the method with the name of `make`.
         */
        if (! ($method_call->name instanceof Identifier)) return [];
        $method_name = $method_call->name->name;
        if ($method_name !== 'make') return [];

        /**
         * We only want to check the method `make` on `ViewFactory`.
         */
        $object_type = $scope->getType($method_call->var);

        if (! ($object_type instanceof ObjectType)) return [];
        if (! in_array($object_type->getClassName(), [ViewFactoryContract::class, ViewFactory::class])) return [];

        /**
         * Here I would like to get the comment below the method call to see the stacktrace.
         * We could be inside a generated file (the result of a Blade compilation) so far from the
         * controller. And this generated file could be the result of another generated file (if there is 
         * an @include inside an @include). The comment below the call contains the full stacktrace until the
         * base controller.
         * 
         * There is a problem with the `getAttribute('comment')` which is not always set I don't know why. I also
         * tried `getDocComment` on the node.
         * 
         * The only solution I found is to fetch the source file and look into the previous line.
         */
        $file_content = file_get_contents($scope->getFile());
        if (! $file_content) return [];

        $lines = explode(PHP_EOL, $file_content);
        $comment = $lines[$method_call->getLine() - 2] ?? ''; // Here it's -2 because line 3 is in index 2 and we want the previous line.

        /**
         * It's possible to not have a comment if the `$view_factory->make()` is called from inside a PHP file, we
         * add the comment only on the Blade files before compilation.
         */
        if ($comment && preg_match('#/\*\* view_name: (?P<view_name>.*), view_path: (?P<view_path>.*), line: (?P<line>\d+), stacktrace: (?P<stacktrace>.*) \*/#', $comment, $matches)) {
            /**
             * We are inside a Blade file with an existing previous stacktrace, in the comment
             * there is also the information (name, path and line) of the current view, just add them to the stacktrace.
             * @todo Here we can verify that `$scope->getFile()` is a tmp file ending in `-blade-compiled.php` and not a project file.
             */

            /** @phpstan-var non-empty-array<array{file: string, line: int, name: ?string}> */
            $stacktrace = json_decode($matches['stacktrace'], associative: true);
            $stacktrace[] = ['file' => $matches['view_path'], 'line' => $matches['line'], 'name' => $matches['view_name']];
        } else {
            /**
             * We are in a PHP file with no previous stacktrace, juste create a stacktrace with this PHP file information.
             * @todo Here we can verify that `$scope->getFile()` is a real project file and not a tmp file.
             */

            /** @phpstan-var non-empty-array<array{file: string, line: int, name: ?string}> */
            $stacktrace = [
                ['file' => $scope->getFile(), 'line' => $method_call->getLine(), 'name' => null],
            ];
        }

        /**
         * Args can be VariadicPlaceholder too `->make(...$args)`, we do not support this here.
         */
        $first_argument = $method_call->args[0] ?? null;
        if (! $first_argument || ($first_argument instanceof VariadicPlaceholder)) return [];
        
        $second_argument = $method_call->args[1] ?? null;
        if ($second_argument && ($second_argument instanceof VariadicPlaceholder)) return [];
        
        $third_argument = $method_call->args[2] ?? null;
        if ($third_argument && ($third_argument instanceof VariadicPlaceholder)) return [];

        /**
         * There is three arguments on the `make()` method:
         * - string $view
         * - array $data
         * - array $mergeData (additional data, often it's `Arr::except(get_defined_vars(), ['__data', '__path'])` to add almost all defined variables to the `@include`)
         * 
         * $data has priority over $mergeData so you can override existing variable with a specific one.
         * 
         * BladeAnalyser will manage the merge.
         */
        return $this->blade_analyser->check($scope, $method_call->getLine(), $first_argument, $second_argument, $third_argument, $stacktrace);
    }
}
