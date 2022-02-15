<?php

namespace ThibaudDauce\PHPStanBlade;

use Exception;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\Node\Arg;
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
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Expr\Array_;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Rules\RuleErrorBuilder;
use Illuminate\Support\ViewErrorBag;
use PhpParser\PrettyPrinter\Standard;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\ViewFinderInterface;
use PHPStan\DependencyInjection\Container;
use PhpParser\ConstExprEvaluationException;
use Illuminate\View\Compilers\BladeCompiler;
use PHPStan\Type\Constant\ConstantStringType;
use Symplify\TemplatePHPStanCompiler\ValueObject\VariableAndType;
use Symplify\TemplatePHPStanCompiler\PHPStan\FileAnalyserProvider;
use Symplify\TemplatePHPStanCompiler\NodeFactory\VarDocNodeFactory;
use ThibaudDauce\PHPStanBlade\PHPVisitors\RemoveEscapeFunctionNodeVisitor;
use ThibaudDauce\PHPStanBlade\PHPVisitors\AddLoopVarTypeToForeachNodeVisitor;
use ThibaudDauce\PHPStanBlade\PHPVisitors\RemoveBrokenEnvVariableCallsVisitor;
use Symplify\TemplatePHPStanCompiler\TypeAnalyzer\TemplateVariableTypesResolver;

class BladeAnalyser
{
    private Registry $registry;
    private Parser $parser;

    public function __construct(
        private ConstExprEvaluator $constExprEvaluator,
        private TemplateVariableTypesResolver $templateVariableTypesResolver,
        private FileAnalyserProvider $fileAnalyserProvider,
        private Standard $printerStandard,
        private VarDocNodeFactory $varDocNodeFactory,
        private LaravelContainer $container,
        private CacheManager $cache_manager,
    ) {
        $parserFactory = new ParserFactory();
        $this->parser  = $parserFactory->create(ParserFactory::PREFER_PHP7);
    }

    public function set_registry(Registry $registry): void
    {
        $this->registry = $registry;
    }

    public function check(Scope $scope, int $controller_line, Arg $view_name_arg, ?Arg $view_parameters_arg): array
    {
        $first_parameter = $view_name_arg->value;
        if ($view_parameters_arg) {
            if ($view_parameters_arg->value instanceof Array_) {
                $parameters_array = $view_parameters_arg->value;
            } else {
                /**
                 * Here the second parameter is not an array, it could be:
                 * - view('welcome', compact('user')) @todo support compact
                 * - view('welcome', $parameters)     @todo support variable array
                 */
                return [];
            }
        } else {
            $parameters_array = new Array_;
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
         * Laravel provides/uses some variables in all the views. Let's add them to the available variables.
         */
        $variables_and_types[] = new VariableAndType('__env', new ObjectType(Factory::class));
        $variables_and_types[] = new VariableAndType('errors', new ObjectType(ViewErrorBag::class));
        $variables_and_types[] = new VariableAndType('component', new ObjectType(AnonymousComponent::class));

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
        return $this->process_view($scope, $controller_line, $view_name, $variables_and_types);
    }
    /**
     * @param VariableAndType[] $variables_and_types
     * @return RuleError[]
     */
    private function process_view(Scope $scope, int $controller_line, string $view_name, array $variables_and_types): array
    {
        // We use Laravel to find the path to the Blade view.
        $view_path = $this->view_finder()->find($view_name);

        /**
         * This function will analyse the Blade view to find errors.
         * The main complexity is to prepare the file to keep the view name and the file number information (so we can
         * repport the error at the right location).
         */
        $html_and_php_content = $this->get_php_and_html_content($scope, $view_name, $view_path);

        
        /**
         * Ok. This is the hard part.
         * We will transform the mix of HTML and PHP to only PHP lines with the comment (view and line number) the line before.
         * 
         * For each line we will try to match the comment first. If there is no comment we will use the previous one.
         * Then we will match a PHP block or an HTML block depending on the previous one (starting with an HTML block
         * because PHP files are HTML by default)
         * On each match we will get the content of the block and the tail (the remaining part of the line).
         * If we are in a PHP block, we will save the content in the `$php_content_lines` with the comment just above.
         * If we are in an HTML block, we will discard the content because HTML is not analyse by PHPStan.
         * 
         * We add a `;` after each PHP block to prevent problems. A PHP line ending with `;;` is correct
         * but a PHP line ending with no `;` is broken.
         */

        $html_and_php_content_lines = explode(PHP_EOL, $html_and_php_content);

        $php_content_lines = [];
        $inside_php = false;
        foreach ($html_and_php_content_lines as $line) {
            preg_match('#(?P<comment>/\*\* view_name: .*?, view_path: .*?, line: \d+, includes_stacktrace: .*? \*/)?(?P<tail>.*)#', $line, $matches);

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

        // If the file is only PHP no errors are possible.
        if (! $php_content_lines) return [];

        array_unshift($php_content_lines, '<?php');

        $php_content = implode(PHP_EOL, $php_content_lines);

        /**
         * We will store our PHP result insice a custom temp folder.
         * If the folder doesn't exists, let's create it.
         */
        $cache_folder = sys_get_temp_dir() . '/phpstan-blade/';
        $cache_file_name =  md5($view_path) . '-blade-compiled.php';

        $tmp_file_path = "{$cache_folder}{$cache_file_name}";
        if (! is_dir($cache_folder)) mkdir($cache_folder);

        /**
         * Then, we'll need to do some modification on the PHP content.
         * We'll use PHPParser to parse the PHP and add/remove/edit some nodes inside the PHP.
         */
        try {
            $stmts = $this->parser->parse($php_content);
        } catch (Exception) {
            file_put_contents($tmp_file_path, $php_content); // I store the content of the PHP inside the file for debugging purposes.
            throw new Exception("Fail to parse the PHP file from view {$view_name} (you can look in {$tmp_file_path} to see the error).");
        }

        /**
         * If no statements w'll fail to parse the PHP file.
         * Right now, I throw an exception but maybe I need to look into what PHPStan do if there is
         * a parse error inside a PHP file. @todo Maybe return an error?
         */
        if (! $stmts) {
            file_put_contents($tmp_file_path, $php_content); // I store the content of the PHP inside the file for debugging purposes.
            throw new Exception("Fail to parse the PHP file from view {$view_name} (you can look in {$tmp_file_path} to see the error).");
        }

        /**
         * To avoid some false positives and improve PHPStan errors,
         * we'll modify the statements with the three following classes.
         * 
         * You can look inside these three classes to have more information about that.
         */
        $stmts = $this->modify_statements($stmts, [
            new AddLoopVarTypeToForeachNodeVisitor,
            new RemoveEscapeFunctionNodeVisitor,
            new RemoveBrokenEnvVariableCallsVisitor,
        ]);

        /**
         * The `varDocNodeFactory` use the result of the `templateVariableTypesResolver` to create the docblock.
         * We will create the [AT]var docblock and add it at the beginning of the file.
         */
        $doc_nodes = $this->varDocNodeFactory->createDocNodes($variables_and_types);
        $stmts = array_merge($doc_nodes, $stmts);

        /**
         * The `printerStandard` allows us to convert the array of PHP statements to a real PHP content.
         * We'll save the content inside a file to analyse it with PHPStan.
         */
        $php_content = $this->printerStandard->prettyPrintFile($stmts);
        file_put_contents($tmp_file_path, $php_content);

        /**
         * Here we use some PHPStan classes (not covered by semver, be careful!) to analyse the file and get the errors.
         */
        $analyse_result = $this->fileAnalyserProvider->provide()->analyseFile($tmp_file_path, [], $this->registry, null); // @phpstan-ignore-line
        $raw_errors = $analyse_result->getErrors(); // @phpstan-ignore-line

        /**
         * PHPStan returns errors with the file name and line number of the compiled PHP file. These lines numbers don't match with the
         * line numbers inside the Blade view (because we add/remove stuff and because one Blade line can generate multiple PHP lines)
         * The goal of the following code is to find for each error the correct line number thanks to the comment added above.
         */
        $php_content_lines = explode(PHP_EOL, $php_content);
        $errors = [];
        foreach ($raw_errors as $raw_error) {
            /**
             * We'll start at the line before the line with the error because we our lines are:
             * - comment
             * - PHP
             * - comment
             * - PHP
             * - comment
             * - PHP
             * - PHP
             * - PHP
             * 
             * Multiple PHP lines are possible because the `printerStandard` can do weird stuff. So we'll look up until we find a comment.
             */

            // This is the index of the PHP line (line number - 1 because the lines array
            // is 0-based but the lines are 1-based). We'll start our do/while with a $comment_index-- so the first 
            // preg_match will be on the line before the errored line.
            $comment_index = $raw_error->getLine() - 1; 
            do {
                $comment_index--;
                $comment_of_line_with_error = $php_content_lines[$comment_index];
                preg_match('#/\*\* view_name: (?P<view_name>.*), view_path: (?P<view_path>.*), line: (?P<line>\d+), includes_stacktrace: (?P<includes_stacktrace>.*) \*/#', $comment_of_line_with_error, $matches);
            } while (! $matches && $comment_index >= 0);

            /**
             * If the first line is before any comment, it should never happen.
             */
            if (! $matches) {
                $line_with_error = $php_content_lines[$raw_error->getLine() - 1];
                throw new Exception("Cannot find comment with view name and lines before \"{$line_with_error}\" for error \"{$raw_error->getMessage()}\"");
            }

            /**
             * We'll create a new error with the view file path and the view file number.
             * We'll also add some metadata to show a nice error title with the `BladeFormatter` class.
             * @todo When support for @include is added, we'll need a way to show a stack trace of information
             */
            $error = RuleErrorBuilder::message($raw_error->getMessage())
                ->file($matches['view_path'])
                ->line($matches['line'])
                ->metadata([
                    'view_name' => $matches['view_name'],
                    'controller_path' => $scope->getFile(),
                    'controller_line' => $controller_line,
                    'includes_stacktrace' => json_decode($matches['includes_stacktrace']),
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
    private function modify_statements(array $stmts, array $nodeVisitors): array
    {
        $nodeTraverser = new NodeTraverser();
        foreach ($nodeVisitors as $nodeVisitor) {
            $nodeTraverser->addVisitor($nodeVisitor);
        }

        return $nodeTraverser->traverse($stmts);
    }

    /**
     * @param array<string, array{0: string, 1: int}> $includes_stacktrace
     */
    private function get_php_and_html_content(Scope $scope, string $view_name, string $view_path, array $includes_stacktrace = []): string
    {
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

        $includes_stacktrace_as_string = json_encode($includes_stacktrace);
        $blade_lines_with_lines_numbers = [];
        foreach ($blade_lines as $i => $line) {
            $line_number = $i + 1;
            $blade_lines_with_lines_numbers[$i] = "/** view_name: {$view_name}, view_path: {$view_path}, line: {$line_number}, includes_stacktrace: {$includes_stacktrace_as_string} */{$line}";
        }

        $blade_content_with_lines_numbers = implode(PHP_EOL, $blade_lines_with_lines_numbers);

        /**
         * The Blade compiler will return us a mix of HTML and PHP.
         * Almost each line will have the comment with view name and line number at the beginning
         * but if one Blade line is compiled to multiple PHP lines the comment is only present on the first line.
         */
        $html_and_php_content =  $this->blade_compiler()->compileString($blade_content_with_lines_numbers);

        /**
         * First we'll try to find all includes and recursivly add them inside the PHP content.
         */
        while (true) {
            preg_match('#<\?php echo \$__env->make\((.+?), \\\Illuminate\\\Support\\\Arr::except\(get_defined_vars\(\), \[\'__data\', \'__path\']\)\)->render\(\); \?>#s', $html_and_php_content, $matches, PREG_OFFSET_CAPTURE);

            if (! $matches) break; // No more includes

            $include_php = $matches[0][0];
            try {
                $include_statements = $this->parser->parse($include_php);
            } catch (Exception $e) {
                throw new Exception("Cannot parse PHP code for include {$include_php}.");
            }
            if (! $include_statements) throw new Exception("Cannot parse PHP code for include {$include_php}.");
            if (count($include_statements) !== 1) throw new Exception("PHP code for include is not one statement {$include_php}.");
            
            $include_statement = $include_statements[0];
            if (! ($include_statement instanceof Echo_)) throw new Exception("PHP code for include is not one echo statement {$include_php}.");

            $render_method_call = $include_statement->exprs[0];
            if ($render_method_call->name->name !== 'render') throw new Exception();

            $make_method_call = $render_method_call->var;
            if ($make_method_call->name->name !== 'make') throw new Exception();

            $args = $make_method_call->getArgs();
            if (count($args) === 0 || count($args) === 1) {
                throw new Exception;
            } elseif (count($args) === 2) {
                $first_parameter = $args[0];
            } elseif (count($args) === 3) {
                $first_parameter = $args[0];
                $second_parameter = $args[1];
            } else {
                throw new Exception;
            }

            if (! ($first_parameter->value instanceof String_)) {
                $html_and_php_content = substr_replace($html_and_php_content, '', $matches[0][1], strlen($matches[0][0]));
                continue;
            }

            $include_view_name = $first_parameter->value->value;
            $include_view_path = $this->view_finder()->find($include_view_name);


            $variables_definitions = [];
            $variables_reseting = [];
            if (isset($second_parameter)) {
                if (! ($second_parameter->value instanceof Array_)) throw new Exception("Second parameter to @include should be an array {$include_php}.");
    
                $variables = $second_parameter->value->items;
    
                $variables_definitions = [];
                $variables_reseting    = [];
                foreach ($variables as $array_item) {
                    if (! $array_item) continue;
                    if (! ($array_item->key instanceof String_)) continue;
    
                    $variableName          = $array_item->key->value;
                    $temporaryVariableName = '__previous' . ucfirst($variableName);
                    $phpExpression         = $this->printerStandard->prettyPrintExpr($array_item->value);
    
                    $variables_definitions[] = sprintf('<?php if (isset($%s)) { $%s = $%s; } ?>', $variableName, $temporaryVariableName, $variableName);
                    $variables_definitions[] = sprintf('<?php $%s = %s; ?>', $variableName, $phpExpression);
    
                    $variables_reseting[] = sprintf('<?php if (isset($%s)) { $%s = $%s; } else { unset($%s); } ?>', $temporaryVariableName, $variableName, $temporaryVariableName, $variableName);
                }
            }

            $new_include_stacktrace = array_merge($includes_stacktrace, [[$view_name, 42]]);

            $include_php_and_html_content = PHP_EOL . implode(PHP_EOL, $variables_definitions) . PHP_EOL . $this->get_php_and_html_content($scope, $include_view_name, $include_view_path, $new_include_stacktrace) . PHP_EOL . implode(PHP_EOL, $variables_reseting) . PHP_EOL;

            $html_and_php_content = substr_replace($html_and_php_content, $include_php_and_html_content, $matches[0][1], strlen($matches[0][0]));
        }

        return $html_and_php_content;
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