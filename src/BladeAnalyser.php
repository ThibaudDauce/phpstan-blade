<?php

namespace ThibaudDauce\PHPStanBlade;

use Exception;
use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\Node\Arg;
use ReflectionProperty;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PHPStan\Type\ThisType;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Registry;
use Illuminate\View\Factory;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPStan\Rules\RuleError;
use PHPStan\Type\ObjectType;
use PhpParser\Node\Expr\Array_;
use PhpParser\ConstExprEvaluator;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Rules\RuleErrorBuilder;
use Illuminate\Support\ViewErrorBag;
use PhpParser\PrettyPrinter\Standard;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\ViewFinderInterface;
use PHPStan\DependencyInjection\Container;
use PhpParser\ConstExprEvaluationException;
use Illuminate\View\Compilers\BladeCompiler;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use Symplify\TemplatePHPStanCompiler\ValueObject\VariableAndType;
use Symplify\TemplatePHPStanCompiler\PHPStan\FileAnalyserProvider;
use Symplify\TemplatePHPStanCompiler\NodeFactory\VarDocNodeFactory;
use ThibaudDauce\PHPStanBlade\PHPVisitors\RemoveEscapeFunctionNodeVisitor;
use ThibaudDauce\PHPStanBlade\PHPVisitors\AddLoopVarTypeToForeachNodeVisitor;
use ThibaudDauce\PHPStanBlade\PHPVisitors\RemoveBrokenEnvVariableCallsVisitor;
use Symplify\TemplatePHPStanCompiler\TypeAnalyzer\TemplateVariableTypesResolver;

class BladeAnalyser
{
    private Parser $parser;

    public function __construct(
        private Container $phpstan_container,
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

    /**
     * @param array<array{file: string, line: int, name: ?string}> $stacktrace
     * @return array<RuleError>
     */
    public function check(Scope $scope, int $controller_line, Arg $view_name_arg, ?Arg $view_parameters_arg, ?Arg $merge_data_arg, array $stacktrace): array
    {
        $tabs = str_repeat("\t", count($stacktrace) - 1);
        // echo "[BLADE] {$tabs} Checking {$scope->getFile()}:{$controller_line}…\n";
        $tabs = str_repeat("\t", count($stacktrace));

        /**
         * We will try to find the string behind the first parameter, it could be:
         * - view('welcome')                                         a simple constant string 
         * - view($view_name) where $view_name = 'welcome';          a variable with a constant string inside
         * - view(view_name()) where view_name() return 'welcome'    a function with a constant return
         */
        $view_name = $this->evaluate_string($view_name_arg->value, $scope);

        // If the first parameter is not constant, we return no errors because we cannot 
        // find the name of the view.
        if (! $view_name) return [];

        // echo "[BLADE] {$tabs} View is {$view_name}\n";

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
        /** @var array<VariableAndType> */
        $variables_and_types = [];

        /**
         * @todo Fix the incorrect merge priority
         */

        $data = $this->get_variables_and_types_from_arg($scope, $view_parameters_arg);
        if ($data) {
            $variables_and_types = array_merge($variables_and_types, $data);
        }

        $merge_data = $this->get_variables_and_types_from_arg($scope, $merge_data_arg);
        if ($merge_data) {
            $variables_and_types = array_merge($variables_and_types, $merge_data);
        }
            
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

        foreach ($variables_and_types as $i => $variable_type) {
            $type = $variable_type->getType();
            if ($type instanceof ConstantBooleanType || $type instanceof ConstantFloatType || $type instanceof ConstantIntegerType || $type instanceof ConstantStringType) {
                $variables_and_types[$i] = new VariableAndType($variable_type->getVariable(), $type->generalize(GeneralizePrecision::lessSpecific()));
            }

            if ($type instanceof NullType) {
                $variables_and_types[$i] = new VariableAndType($variable_type->getVariable(), new MixedType);
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
        return $this->process_view($scope, $controller_line, $view_name, $variables_and_types, $stacktrace);
    }
    /**
     * @param VariableAndType[] $variables_and_types
     * @param array<array{file: string, line: int, name: ?string}> $stacktrace
     * @return array<RuleError>
     */
    private function process_view(Scope $scope, int $controller_line, string $view_name, array $variables_and_types, array $stacktrace): array
    {
        $tabs = str_repeat("\t", count($stacktrace));

        // We use Laravel to find the path to the Blade view.
        $view_path = $this->view_finder()->find($view_name);

        // echo "[BLADE] {$tabs} View path is {$view_path}\n";

        /**
         * This function will analyse the Blade view to find errors.
         * The main complexity is to prepare the file to keep the view name and the file number information (so we can
         * repport the error at the right location).
         */
        $html_and_php_content = $this->get_php_and_html_content($scope, $view_name, $view_path, $stacktrace);

        
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
            preg_match('#(?P<comment>/\*\* view_name: .*?, view_path: .*?, line: \d+, stacktrace: .*? \*/)?(?P<tail>.*)#', $line, $matches);

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
        $cache_file_name = md5($view_path . json_encode($stacktrace)) . '-blade-compiled.php';

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
        // echo "[BLADE] {$tabs} Analysing {$tmp_file_path} ({$view_name}) with PHPStan…\n";
        $start = microtime(true);
        $analyse_result = $this->fileAnalyserProvider->provide()->analyseFile($tmp_file_path, [], $this->phpstan_container->getByType(Registry::class), null); // @phpstan-ignore-line
        $time = number_format(microtime(true) - $start, 2);
        // echo "[BLADE] {$tabs} End of analyse of {$tmp_file_path} ({$view_name}) in {$time}s.\n";
        
        $raw_errors = $analyse_result->getErrors(); // @phpstan-ignore-line

        /**
         * PHPStan returns errors with the file name and line number of the compiled PHP file. These lines numbers don't match with the
         * line numbers inside the Blade view (because we add/remove stuff and because one Blade line can generate multiple PHP lines)
         * The goal of the following code is to find for each error the correct line number thanks to the comment added above.
         */
        $php_content_lines = explode(PHP_EOL, $php_content);
        $errors = [];
        foreach ($raw_errors as $raw_error) {
            if ($raw_error->getMetadata()['view_name'] ?? null) {
                if (! $line = $raw_error->getLine()) throw new Exception("Receive a view error without line number");

                $error = RuleErrorBuilder::message($raw_error->getMessage())
                    ->file($raw_error->getFile())
                    ->line($line)
                    ->metadata($raw_error->getMetadata())
                    ->build();
                $errors[] = $error;
                continue;
            }

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
                preg_match('#/\*\* view_name: (?P<view_name>.*), view_path: (?P<view_path>.*), line: (?P<line>\d+), stacktrace: (?P<stacktrace>.*) \*/#', $comment_of_line_with_error, $matches);
            } while (! $matches && $comment_index >= 0);

            /**
             * If the first line is before any comment, it should never happen.
             */
            if (! $matches) {
                $line_with_error = $php_content_lines[$raw_error->getLine() - 1];
                throw new Exception("Cannot find comment with view name and lines before \"{$line_with_error}\" for error \"{$raw_error->getMessage()}\" on line  {$raw_error->getLine()} (look into {$tmp_file_path}.");
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
                    'stacktrace' => json_decode($matches['stacktrace'], associative: true),
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
     * @param array<string, array{file: string, line: int, name: ?string}> $stacktrace
     */
    private function get_php_and_html_content(Scope $scope, string $view_name, string $view_path, array $stacktrace = []): string
    {
        $tabs = str_repeat("\t", count($stacktrace));

        /**
         * There is some problems with the PHPStan cache, if you want more information go inside the CacheManager class
         * but it's not required to understand the `process_view` function.
         */
        $this->cache_manager->add_dependency_to_view_file($scope->getFile(), $view_path);

        /**
         * We get the Blade content, if the file doesn't exists, Larastan should catch this, so we return no errors here.
         * If there is no content inside the view file, there is no error possible so return early.
         */
        if (! file_exists($view_path)) return '';

        $blade_content = file_get_contents($view_path);
        if (! $blade_content) return '';

        /**
         * We add a comment before each Blade line with the view name and the line.
         */
        $blade_lines = explode(PHP_EOL, $blade_content);

        $stacktrace_as_string = json_encode($stacktrace);
        $blade_lines_with_lines_numbers = [];
        foreach ($blade_lines as $i => $line) {
            $line_number = $i + 1;
            $blade_lines_with_lines_numbers[$i] = "/** view_name: {$view_name}, view_path: {$view_path}, line: {$line_number}, stacktrace: {$stacktrace_as_string} */{$line}";
        }

        $blade_content_with_lines_numbers = implode(PHP_EOL, $blade_lines_with_lines_numbers);

        /**
         * The Blade compiler will return us a mix of HTML and PHP.
         * Almost each line will have the comment with view name and line number at the beginning
         * but if one Blade line is compiled to multiple PHP lines the comment is only present on the first line.
         */
        // echo "[BLADE] {$tabs} Compiling {$view_path} with Blade compiler…\n";
        $start = microtime(true);
        $html_and_php_content =  $this->blade_compiler()->compileString($blade_content_with_lines_numbers);
        $time = number_format(microtime(true) - $start, 2);
        // echo "[BLADE] {$tabs} End of compilation of {$view_path} in {$time}s.\n";

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

    /**
     * @return array<VariableAndType>
     */
    private function get_variables_and_types_from_arg(Scope $scope, ?Arg $array_argument): ?array
    {
        if (! $array_argument) return [];

        if ($array_argument->value instanceof Array_) {
            return  $this->templateVariableTypesResolver->resolveArray($array_argument->value, $scope);
        } else {
            /**
             * The argument could be:
             * - compact('user')                                               @todo
             * - $parameter                 where $parameter = […]             @todo
             * - \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path'])
             */

            if (
                $array_argument->value instanceof StaticCall &&
                $array_argument->value->class instanceof Name &&
                $scope->resolveName($array_argument->value->class) === Arr::class &&
                $array_argument->value->name instanceof Identifier &&
                $array_argument->value->name->name === 'except' &&
                $array_argument->value->getArgs()[0]->value instanceof FuncCall &&
                $array_argument->value->getArgs()[0]->value->name instanceof Name &&
                $scope->resolveName($array_argument->value->getArgs()[0]->value->name) === 'get_defined_vars' &&
                $array_argument->value->getArgs()[1]->value instanceof Array_
            ) {
                /**
                 * @todo Check the second argument to except and remove these keys.
                 */

                $result = [];
                foreach ($scope->getDefinedVariables() as $variable_name) {
                    $result[] = new VariableAndType($variable_name, $scope->getVariableType($variable_name));
                }
                return $result;
            }

            return null;
        }
    }
}