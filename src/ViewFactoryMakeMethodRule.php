<?php

namespace ThibaudDauce\PHPStanBlade;

use PhpParser;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Registry;
use PHPStan\Type\ObjectType;
use PhpParser\Node\Identifier;
use PhpParser\Node\Expr\MethodCall;
use Illuminate\Contracts\View\Factory;
use PHPStan\DependencyInjection\Container;

/**
 * @implements Rule<MethodCall>
 */
class ViewFactoryMakeMethodRule implements Rule
{
    public function __construct(
        private Container $container,
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
        if ($object_type->getClassName() !== Factory::class) return [];

        // Check the correct method
        if (! ($method_call->name instanceof Identifier)) return [];
        $method_name = $method_call->name->name;

        dump($method_name);
        if ($method_name !== 'make') return [];

        $this->blade_analyser->set_registry($this->container->getByType(Registry::class));
        dump(count($method_call->args));

        if (count($method_call->args) === 0) {
            return [];
        } elseif (count($method_call->args) === 1) {
            return $this->blade_analyser->check($scope, $method_call->getLine(), $method_call->args[0], null);
        } elseif (count($method_call->args) === 2) {
            // @todo check the second argument to see if it's data or mergeData
            return $this->blade_analyser->check($scope, $method_call->getLine(), $method_call->args[0], $method_call->args[1]);
        } elseif (count($method_call->args) === 3) {
            return $this->blade_analyser->check($scope, $method_call->getLine(), $method_call->args[0], $method_call->args[1]);
        }

        return [];
    }
}
