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
use PhpParser\Node\Arg;
use PhpParser\Node\VariadicPlaceholder;

/**
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
        $object_type = $scope->getType($method_call->var);

        // Check the correct object
        if (! ($object_type instanceof ObjectType)) return [];
        if (! in_array($object_type->getClassName(), [ViewFactoryContract::class, ViewFactory::class])) return [];

        // Check the correct method
        if (! ($method_call->name instanceof Identifier)) return [];
        $method_name = $method_call->name->name;
        if ($method_name !== 'make') return [];

        $file_content = file_get_contents($scope->getFile());
        if (! $file_content) return [];

        $lines = explode(PHP_EOL, $file_content);
        $comment = $lines[$method_call->getLine() - 2] ?? '';

        if ($comment && preg_match('#/\*\* view_name: (?P<view_name>.*), view_path: (?P<view_path>.*), line: (?P<line>\d+), stacktrace: (?P<stacktrace>.*) \*/#', $comment, $matches)) {
            /** @var array<array{file: string, line: int, name: ?string}> */
            $stacktrace = json_decode($matches['stacktrace'], associative: true);
            $stacktrace[] = ['file' => $matches['view_path'], 'line' => $matches['line'], 'name' => $matches['view_name']];
        } else {
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
         * @todo merge the third argument with the second.
         */
        return $this->blade_analyser->check($scope, $method_call->getLine(), $first_argument, $second_argument, $third_argument, $stacktrace);
    }
}
