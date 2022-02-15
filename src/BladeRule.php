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
use PhpParser\Node\Expr\FuncCall;
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

        $number_of_parameters = count($funcCall->getArgs());

        if ($number_of_parameters === 0) {
            return [];
        } elseif ($number_of_parameters === 1) {
            $first_parameter = $funcCall->getArgs()[0]->value;
            $parameters_array = new Node\Expr\Array_();
        } elseif ($number_of_parameters === 2) {
            $first_parameter  = $funcCall->getArgs()[0]->value;
            $second_parameter = $funcCall->getArgs()[1]->value;

            if ($second_parameter instanceof Node\Expr\Array_) {
                $parameters_array = $second_parameter;
            } else {
                /**
                 * Here the second parameter is not an array, it could be:
                 * - view('welcome', compact('user')) @todo support compact
                 * - view('welcome', $parameters)     @todo support variable array
                 */
                return [];
            }
        } else {
            return [];
        }

        /**
         * We will try to find the string behind the first parameter, it could be:
         * - view('welcome')                                         a simple constant string 
         * - view($view_name) where $view_name = 'welcome';          a variable with a constant string inside
         * - view(view_name()) where view_name() return 'welcome'    a function with a constant return
         */
        $view_name = $this->evaluate_string($first_parameter, $scope);

        // If the first parameter is not constant, we return no errors because we cannot 
        // find the name of the view.
        if (! $view_name) return [];

        /**
         * Here we use the `templateVariableTypesResolver` to transform the view parameters array to a list of 
         * names and types. This array will be use bellow to generate a doc block.
         * 
         * For example, if we have:
         * view('welcome', [
         *     'name' => 'Thibaud',
         *     'age' => $user->age,
         *     'users' => User::all(),
         * ]);
         * 
         * We need to have an array:
         * [
         *     new VariableAndType('name', string),
         *     new VariableAndType('age', int),
         *     new VariableAndType('users', Collection<User>),
         * ]
         * 
         * This array could be transform in a docblock (where [AT] is @):
         * 
         * [AT]var string $name
         * [AT]var int $age
         * [At]var Illuminate\Eloquent\Collection<App\Models\User> $users
         */
        $variables_and_types = $this->templateVariableTypesResolver->resolveArray($parameters_array, $scope);

            
        /**
         * If the view parameters contains `$this` the type found by `templateVariableTypesResolver` is incorrect.
         *
         * For example, inside a `Invoice` model:
         * return view('invoice', [
         *     'invoice' => $this,
         * ]);
         * 
         * `templateVariableTypesResolver` will return:
         *  new VariableAndType('invoice', ThisType<Invoice>)
         * 
         * And generate a docblock:
         * [AT]var $this(Invoice) $invoice
         * 
         * This docblock is incorrect so we need to replace the `ThisType<Invoice>` by just `Invoice` to have the correct docblock:
         * [AT]var Invoice $invoice
         * 
         * The method ThisType::getStaticObjectType() returns the type of the object inside `ThisType`.
         */
        foreach ($variables_and_types as $i => $variable_type) {
            $type = $variable_type->getType();
            if ($type instanceof ThisType) {
                $variables_and_types[$i] = new VariableAndType($variable_type->getVariable(), $type->getStaticObjectType());
            }
        }


        /**
         * LIVEWIRE MANAGEMENT
         * 
         * Inside a Livewire view we have access to public properties directly without passing them to the view function.
         * We also have access to `$this` (the component object).
         */
        if ($scope->isInClass()) {
            $class = $scope->getClassReflection();
            if (! $class) {
                throw new Exception('`$scope->isInClass()` returned `true` but `$scope->getClassReflection()` returned `null`. Is it possible?');
            }

            // I don't require Livewire inside this package so I use the string class version.
            if ($class->isSubclassOf('Livewire\Component')) {
                /** @var string The real type is class-string but VSCode has problems with that… :-( Sad… */
                $class_name = $class->getName();

                // The `$this` variable is available inside the view.
                $variables_and_types[] = new VariableAndType('this', new ObjectType($class_name));

                // All public properties of the class are available to the view.
                $properties = $class->getNativeReflection()->getProperties(ReflectionProperty::IS_PUBLIC);
                foreach ($properties as $property) {
                    $variables_and_types[] = new VariableAndType($property->name, $class->getProperty($property->name, $scope)->getReadableType());
                }
            }
        }

        /**
         * We have the view name, we have the view parameters, let's analyse the Blade content!
         */
        return $this->process_view($scope, $funcCall, $view_name, $variables_and_types);
    }

    /**
     * @param VariableAndType[] $variables_and_types
     * @return RuleError[]
     */
    private function process_view(Scope $scope, FuncCall $node, string $view_name, array $variables_and_types): array
    {
        /**
         * This function will analyse the Blade view to find errors.
         * The main complexity is to prepare the file to keep the view name and the file number information (so we can
         * repport the error at the right location).
         */


        // We use Laravel to find the path to the Blade view.
        $view_path = $this->view_finder()->find($view_name);

        /**
         * There is some problems with the PHPStan cache, if you want more information go inside the CacheManager class
         * but it's not required to understand the `process_view` function.
         */
        $this->cache_manager->add_dependency_to_view_file($scope->getFile(), $view_path);

        /**
         * We get the Blade content, if the file doesn't exists, Larastan should catch this, so we return no errors here.
         * If there is no content inside the view file, there is no error possible so return early.
         */
        if (! file_exists($view_path)) return [];

        $blade_content = file_get_contents($view_path);
        if (! $blade_content) return [];

        /**
         * We add a comment before each Blade line with the view name and the line.
         */
        $blade_lines = explode(PHP_EOL, $blade_content);

        $blade_lines_with_lines_numbers = [];
        foreach ($blade_lines as $i => $line) {
            $line_number = $i + 1;
            $blade_lines_with_lines_numbers[$i] = "/** view: {$view_name}, line: {$line_number} */{$line}";
        }

        $blade_content_with_lines_numbers = implode(PHP_EOL, $blade_lines_with_lines_numbers);


        /**
         * The Blade compiler will return us a mix of HTML and PHP.
         * Almost each line will have the comment with view name and line number at the beginning
         * but if one Blade line is compiled to multiple PHP lines the comment is only present on the first line.
         */
        $html_and_php_content = $this->blade_compiler()->compileString($blade_content_with_lines_numbers);

        $html_and_php_content_lines = explode(PHP_EOL, $html_and_php_content);

        $php_content_lines = [];
        $inside_php = false;
        foreach ($html_and_php_content_lines as $line) {
            preg_match('#(?P<comment>/\*\* view: .*?, line: \d+ \*/)?(?P<tail>.*)#', $line, $matches);

            if (! $matches || ! $matches['tail']) continue;

            if ($matches['comment']) {
                $comment = $matches['comment'];
            }
            $tail = $matches['tail'];

            if (! isset($comment)) {
                throw new Exception("Found a PHP line before the first comment indicating the view file and line number.");
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
            throw new Exception("Fail to parse the PHP file from view {$view_name} (you can look in {$tmp_file_path} to see the error).");
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
                preg_match('#/\*\* view: (?P<view_name>.*), line: (?P<line>\d+) \*/#', $comment_of_line_with_error, $matches);

            } while (! $matches && $comment_line >= 0);

            if (! $matches) {
                throw new Exception("Cannot find comment with view name and lines before \"{$line_with_error}\" for error \"{$raw_error->getMessage()}\"");
            }

            $error = RuleErrorBuilder::message($raw_error->getMessage())
                ->file($scope->getFile())
                ->line($matches['line'])
                ->metadata([
                    'view_name' => $view_name,
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

    private function blade_compiler(): BladeCompiler
    {
        /** @var BladeCompiler */
        $blade_compiler = $this->container->make(BladeCompiler::class);
        $blade_compiler->withoutComponentTags();

        return $blade_compiler;
    }
}
