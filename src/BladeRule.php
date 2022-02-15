<?php

namespace ThibaudDauce\PHPStanBlade;

use Exception;
use PhpParser\Node;
use PhpParser\Parser;
use Livewire\Component;
use PHPStan\Rules\Rule;
use ReflectionProperty;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Type\ThisType;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Registry;
use Illuminate\View\Factory;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPStan\Rules\RuleError;
use PHPStan\Type\ObjectType;
use PhpParser\ConstExprEvaluator;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Rules\RuleErrorBuilder;
use Illuminate\Support\ViewErrorBag;
use PhpParser\PrettyPrinter\Standard;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\ViewFinderInterface;
use PhpParser\ConstExprEvaluationException;
use Illuminate\View\Compilers\BladeCompiler;
use PHPStan\Type\Constant\ConstantStringType;
use Symplify\TemplatePHPStanCompiler\ValueObject\VariableAndType;
use Symplify\TemplatePHPStanCompiler\PHPStan\FileAnalyserProvider;
use Symplify\TemplatePHPStanCompiler\NodeFactory\VarDocNodeFactory;
use ThibaudDauce\PHPStanBlade\PHPVisitors\AddLoopVarTypeToForeachNodeVisitor;
use Vural\PHPStanBladeRule\PHPParser\NodeVisitor\RemoveEnvVariableNodeVisitor;
use Symplify\TemplatePHPStanCompiler\TypeAnalyzer\TemplateVariableTypesResolver;
use Vural\PHPStanBladeRule\PHPParser\NodeVisitor\RemoveEscapeFunctionNodeVisitor;

/**
 * @implements Rule<Node>
 */
class BladeRule implements Rule
{
    private Registry $registry;
    private Parser $parser;

    /**
     * @param Rule[] $rules
     * @phpstan-param Rule<Node>[] $rules
     */
    public function __construct(
        array $rules,
        private ConstExprEvaluator $constExprEvaluator,
        private TemplateVariableTypesResolver $templateVariableTypesResolver,
        private FileAnalyserProvider $fileAnalyserProvider,
        private Standard $printerStandard,
        private VarDocNodeFactory $varDocNodeFactory,
        private LaravelContainer $container,
        private CacheManager $cache_manager,
    ) {
        $this->registry = new Registry($rules); // @phpstan-ignore-line

        $parserFactory = new ParserFactory();
        $this->parser  = $parserFactory->create(ParserFactory::PREFER_PHP7);
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @inheritDoc */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof Node\Expr\FuncCall) {
            return [];
        }

        $funcCall = $node;

        $funcName = $funcCall->name;

        if (! $funcName instanceof Node\Name) return [];

        $funcName = $scope->resolveName($funcName);

        if ($funcName !== 'view') return [];

        // TODO: maybe make sure this function is coming from Laravel

        if (count($funcCall->getArgs()) === 0) return [];

        $template = $funcCall->getArgs()[0]->value;

        $template_name = $this->evaluate_string($template, $scope);

        if (! $template_name) return [];

        $args = $funcCall->getArgs();

        if (count($args) === 1) {
            $parameters_array = new Node\Expr\Array_();
        } elseif (count($args) === 2) {
            if ($args[1]->value instanceof Node\Expr\Array_) {
                $parameters_array = $args[1]->value;
            } else {
                // TODO disable compact.
                $parameters_array = new Node\Expr\Array_();
            }
        } else {
            throw new Exception("Cannot call view with " . count($args) . " arguments");
        }

        $variables_and_types = $this->templateVariableTypesResolver->resolveArray($parameters_array, $scope);

        // Fix $this type
        foreach ($variables_and_types as $i => $variable_type) {
            $type = $variable_type->getType();
            if ($type instanceof ThisType) {
                $variables_and_types[$i] = new VariableAndType($variable_type->getVariable(), $type->getStaticObjectType());
            }
        }

        if ($scope->isInClass()) {
            $class = $scope->getClassReflection();
            if (! $class) {
                throw new Exception('`$scope->isInClass()` returned `true` but `$scope->getClassReflection()` returned `null`. Is it possible?');
            }

            // @phpstan-ignore-next-line
            if ($class->isSubclassOf(Component::class)) {
                /** @var string */
                $class_name = $class->getName();
                $variables_and_types[] = new VariableAndType('this', new ObjectType($class_name));

                $properties = $class->getNativeReflection()->getProperties(ReflectionProperty::IS_PUBLIC);

                foreach ($properties as $property) {
                    if ($property->class !== $class->getNativeReflection()->getName()) continue;

                    $variables_and_types[] = new VariableAndType($property->name, $class->getProperty($property->name, $scope)->getReadableType());
                }
            }
        }

        return $this->process_template($scope, $node, $template_name, $variables_and_types);
    }

    private function evaluate_string(Expr $expr, Scope $scope): ?string
    {
        try {
            $result = $this->constExprEvaluator->evaluateDirectly($expr);
            if (is_string($result)) return $result;
        } catch (ConstExprEvaluationException) {
        }

        $exprType = $scope->getType($expr);

        if ($exprType instanceof ConstantStringType) {
            return $exprType->getValue();
        }

        return null;
    }

    private function view_finder(): ViewFinderInterface
    {
        return $this->container->make(Factory::class)->getFinder();
    }

    /**
     * @param VariableAndType[] $variables_and_types
     * @return RuleError[]
     */
    private function process_template(Scope $scope, Node $node, string $template_name, array $variables_and_types): array
    {
        $template_path = $this->view_finder()->find($template_name);

        $this->cache_manager->add_dependency_to_template_file($scope->getFile(), $template_path);

        $blade_content = file_get_contents($template_path);

        if (! $blade_content) {
            return []; // TODO return error?
        }

        $blade_lines = explode(PHP_EOL, $blade_content);

        $blade_lines_with_lines_numbers = [];
        foreach ($blade_lines as $i => $line) {
            $line_number = $i + 1;
            $blade_lines_with_lines_numbers[$i] = "/** template: {$template_name}, line: {$line_number} */{$line}";
        }

        $blade_content_with_lines_numbers = implode(PHP_EOL, $blade_lines_with_lines_numbers);

        $blade_compiler = $this->container->make(BladeCompiler::class);
        $blade_compiler->withoutComponentTags();

        $html_and_php_content = $blade_compiler->compileString($blade_content_with_lines_numbers);

        $html_and_php_content_lines = explode(PHP_EOL, $html_and_php_content);

        $php_content_lines = [];
        $inside_php = false;
        foreach ($html_and_php_content_lines as $line) {
            preg_match('#(?P<comment>/\*\* template: .*?, line: \d+ \*/)?(?P<tail>.*)#', $line, $matches);

            if (! $matches || ! $matches['tail']) continue;

            if ($matches['comment']) {
                $comment = $matches['comment'];
            }
            $tail = $matches['tail'];

            if (! isset($comment)) {
                throw new Exception("Found a PHP line before the first comment indicating the template file and line number.");
            }
            while (true) {
                if ($inside_php) {
                    preg_match('#(?P<php>.*?)\?>(?P<tail>.*)#', $tail, $matches);
                    if (! $matches) {
                        // All the tail is PHP. Saving the line and going to the next line.
                        if (trim($tail)) {
                            $php_content_lines[] = $comment;
                            $php_content_lines[] = trim($tail);
                        }

                        break;
                    }

                    $inside_php = false;

                    if ($matches['php']) {
                        $php_content_lines[] = $comment;
                        $php_content_lines[] = $matches['php'] . ';';
                    }

                    if (! $matches['tail']) {
                        // We close a PHP tag at the end of line (because no more tail). Going to the next line in HTML mode.
                        break;
                    }

                    $tail = $matches['tail'];
                } else {
                    preg_match('#(?P<html>.*?)<\?php(?P<tail>.*)#', $tail, $matches);
                    if (! $matches) {
                        // No more PHP opening in this line, so the $tail is only HTML. Going to the next line.
                        break;
                    }

                    $inside_php = true;

                    if (! $matches['tail']) {
                        // We open a PHP tag at the end of line (because no more tail). Going to the next line in PHP mode.
                        break;
                    }

                    // Continuing to match the tail in PHP mode…
                    $tail = $matches['tail'];
                }
            }
        }

        if ($php_content_lines) {
            array_unshift($php_content_lines, '<?php');
        }

        $php_content = implode(PHP_EOL, $php_content_lines);
        if (! $php_content) {
            return [];
        }

        $tmp_file_path = sys_get_temp_dir() . '/phpstan-blade/' . md5($scope->getFile()) . '-blade-compiled.php';
        if (! is_dir(sys_get_temp_dir() . '/phpstan-blade/')) {
            mkdir(sys_get_temp_dir() . '/phpstan-blade/');
        }

        $stmts = $this->parser->parse($php_content);

        if (! $stmts) {
            file_put_contents($tmp_file_path, $php_content);
            throw new Exception("Fail to parse the PHP file from view {$template_name} (you can look in {$tmp_file_path} to see the error).");
        }

        $stmts = $this->traverseStmtsWithVisitors($stmts, [
            new AddLoopVarTypeToForeachNodeVisitor,
            new RemoveEscapeFunctionNodeVisitor,
            new RemoveEnvVariableNodeVisitor,
        ]);

        // var_dump($variables_and_types);

        $variables_and_types[] = new VariableAndType('__env', new ObjectType(Factory::class));
        $variables_and_types[] = new VariableAndType('errors', new ObjectType(ViewErrorBag::class));
        $variables_and_types[] = new VariableAndType('component', new ObjectType(AnonymousComponent::class));

        $doc_nodes = $this->varDocNodeFactory->createDocNodes($variables_and_types);
        $stmts = array_merge($doc_nodes, $stmts);

        $php_content = $this->printerStandard->prettyPrintFile($stmts);

        file_put_contents($tmp_file_path, $php_content);

        $analyse_result = $this->fileAnalyserProvider->provide()->analyseFile($tmp_file_path, [], $this->registry, null); // @phpstan-ignore-line
        $raw_errors = $analyse_result->getErrors(); // @phpstan-ignore-line

        $php_content_lines = explode(PHP_EOL, $php_content);
        $errors = [];
        foreach ($raw_errors as $raw_error) {
            $line_with_error = $php_content_lines[$raw_error->getLine() - 1];

            $comment_line = $raw_error->getLine() - 1;
            $matches = [];
            do {
                $comment_line--;
                $comment_of_line_with_error = $php_content_lines[$comment_line];
                preg_match('#/\*\* template: (?P<template_name>.*), line: (?P<line>\d+) \*/#', $comment_of_line_with_error, $matches);

            } while (! $matches && $comment_line >= 0);

            if (! $matches) {
                throw new Exception("Cannot find comment with template name and lines before \"{$line_with_error}\" for error \"{$raw_error->getMessage()}\"");
            }

            $error = RuleErrorBuilder::message($raw_error->getMessage())
                ->file($scope->getFile())
                ->line($matches['line'])
                ->metadata([
                    'template_name' => $template_name,
                    'view_function_line' => $node->getLine(),
                ])
                ->build();
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * @param Stmt[]                $stmts
     * @param NodeVisitorAbstract[] $nodeVisitors
     *
     * @return Node[]
     */
    private function traverseStmtsWithVisitors(array $stmts, array $nodeVisitors): array
    {
        $nodeTraverser = new NodeTraverser();
        foreach ($nodeVisitors as $nodeVisitor) {
            $nodeTraverser->addVisitor($nodeVisitor);
        }

        return $nodeTraverser->traverse($stmts);
    }

}
