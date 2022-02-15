<?php

namespace ThibaudDauce\PHPStanBlade;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Registry;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\DependencyInjection\Container;

/**
 * @implements Rule<FuncCall>
 */
class ViewFunctionRule implements Rule
{
    public function __construct(
        private BladeAnalyser $blade_analyser,
    ) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /** @inheritDoc */
    public function processNode(Node $funcCall, Scope $scope): array
    {
        // We only watch function calls right now.
        if (! $funcCall instanceof FuncCall) return [];

        /**
         * MATCH VIEW FUNCTION
         * 
         * First we will try to find the function name to see if it's `view()`.
         * If we cannot find the function name or if the name is not `view`,
         * we will return an empty array: no errors. 
         */

        /**
         * The function name is an expression, hard to find the real string name here…
         * @todo We could try `evaluate_string` to fetch the name if it's a constant expression, for example:
         *     $function = 'view';
         *     {$function}('welcome', []);
         * But not sure if it's something really useful…
         */
        if (! $funcCall->name instanceof Node\Name) return [];

        /**
         * The scope allows us to resolve the real string behind the Node\Name.
         */
        $funcName = $scope->resolveName($funcCall->name);
        if ($funcName !== 'view') return [];

        /**
         * Here the `view()` function could be a user-defined function, maybe we should check
         * if it's the `view()` function from Laravel. Not sure how to do that… @todo
         */

        /**
         * FIND VIEW PARAMETERS
         * 
         * Now, we know that we are calling the `view()` function, 
         * we need to check the function parameters:
         * - 0 parameter:  the `view()` function without any parameter returns the `ViewFactory` to do something different.
         * - 1 parameter:  the view has no parameters
         * - 2 parameters: the view has an array inside the second parameter with parameters.
         * - more that 2 parameters: it's an error
         */
        if (empty($funcCall->getArgs())) return [];

        return $this->blade_analyser->check($scope, $funcCall->getLine(), $funcCall->getArgs()[0], $funcCall->getArgs()[1] ?? null);
    }
}
