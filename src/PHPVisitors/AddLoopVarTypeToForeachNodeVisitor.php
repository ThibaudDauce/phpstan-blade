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
use ThibaudDauce\PHPStanBlade\Loop;

class AddLoopVarTypeToForeachNodeVisitor extends NodeVisitorAbstract
{
    private ?Expr $expr = null;

    private int $loop_count = 0;

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

    /** @return Node[]|null */
    public function leaveNode(Node $node): ?array
    {
        if ($node instanceof Foreach_) {
            $this->loop_count--;
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

        if (! $this->loop_count) {
            $doc_nop = new Nop();
            $doc_nop->setDocComment(new Doc(sprintf(
                '/** @var %s $%s */',
                '\\' . Loop::class,
                'loop'
            )));
    
            // Add `$loop` var doc type as the first statement
            array_unshift($foreach->stmts, $doc_nop);
        }

        // Add `unset($loop)` at the end of the loop
        // to prevent accessing this variable outside of loop
        if (! $this->loop_count) {
            $foreach->stmts[] = new Node\Stmt\Unset_([new Variable('loop')]);
        }

        $this->loop_count++;

        return;
    }
}
