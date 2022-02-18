<?php

namespace ThibaudDauce\PHPStanBlade;

use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Analyser\Scope;
use PhpParser\Node\Expr\FuncCall;

/**
 * The goal of this Rule is to match the `view()` function call
 * 
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
    public function processNode(Node $function_call, Scope $scope): array
    {
        /**
         * This condition is not require to work because we specify `FuncCall` in `getNodeType()` above
         * but it helps VSCode to understand and provide auto-completion below.
         */
        if (! $function_call instanceof FuncCall) return [];

        /**
         * MATCH VIEW FUNCTION `view()`
         * 
         * First we will try to find the function name to see if it's `view()`.
         * If we cannot find the function name or if the name is not `view`,
         * we will return an empty array: no errors. 
         */

        /**
         * The function name is an expression, hard to find the real string name here…
         * We could try to evaluate the string but do not support this right now. 
         */
        if (! $function_call->name instanceof Node\Name) return [];

        $funcName = $scope->resolveName($function_call->name);
        if ($funcName !== 'view') return [];

        /**
         * Here the `view()` function could be a user-defined function, maybe we should check
         * if it's the `view()` function from Laravel. Not sure how to do that… @todo
         */

        /**
         * If we provide no arguments it's not a view render so no errors here.
         */
        if (empty($function_call->getArgs())) return [];

        /**
         * Let's analyse the view!
         */
        return $this->blade_analyser->check(
            $scope,
            $function_call->getLine(),
            view_name_arg: $function_call->getArgs()[0],
            view_parameters_arg: $function_call->getArgs()[1] ?? null,
            merge_data_arg: null,
            stacktrace: [
                ['file' => $scope->getFile(), 'line' => $function_call->getLine(), 'name' => null],
            ]
        );
    }
}
