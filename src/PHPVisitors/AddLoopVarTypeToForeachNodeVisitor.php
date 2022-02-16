<?php

namespace ThibaudDauce\PHPStanBlade\PHPVisitors;

use Exception;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Comment\Doc;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeVisitorAbstract;
use Vural\PHPStanBladeRule\ValueObject\Loop;

class AddLoopVarTypeToForeachNodeVisitor extends NodeVisitorAbstract
{
    private ?Expr $expr = null;

    /** @return Node[]|null */
    public function enterNode(Node $node): ?array
    {
        if ($node instanceof Assign) {
            $this->save_assign($node);
        }

        if ($node instanceof Foreach_) {
            $this->replace_current_loop($node);
        }

        return null;
    }

    private function save_assign(Assign $assign): void
    {
        if (! $assign->var instanceof Variable) {
            return;
        }

        $variable_name = $assign->var->name;

        if (! is_string($variable_name)) {
            return;
        }

        if ($variable_name === '__currentLoopData') {
            $this->expr = $assign->expr;
        }

        return;
    }

    private function replace_current_loop(Foreach_ $foreach): void
    {
        if (! $foreach->expr instanceof Variable) {
            return;
        }

        $variable_name = $foreach->expr->name;

        if (! is_string($variable_name)) {
            return;
        }

        if ($variable_name !== '__currentLoopData') {
            return;
        }

        if (! $this->expr) {
            throw new Exception('Found a `foreach($__currentLoopData)` without getting a `$__currentLoopData = …` before.');
        }

        $foreach->expr = $this->expr;

        $doc_nop = new Nop();
        $doc_nop->setDocComment(new Doc(sprintf(
            '/** @var %s $%s */',
            '\\' . Loop::class,
            'loop'
        )));

        // Add `$loop` var doc type as the first statement
        array_unshift($foreach->stmts, $doc_nop);

        // `endforeach` also has a doc comment. Remove that before adding our unset.
        array_pop($foreach->stmts);

        // Add `unset($loop)` at the end of the loop
        // to prevent accessing this variable outside of loop
        $foreach->stmts[] = new Node\Stmt\Unset_([new Variable('loop')]);

        return;
    }
}
