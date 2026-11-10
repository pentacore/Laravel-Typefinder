<?php

namespace Tests\Unit\Analysis;

use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use Pentacore\Typefinder\Analysis\ReturnedPart;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnedArrayReaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function method(string $body, string $header = "namespace App\\Http\\Resources;\n"): ClassMethod
    {
        $path = tempnam(sys_get_temp_dir(), 'returnedarray');
        $this->files[] = $path;
        file_put_contents($path, "<?php\n{$header}\nclass Subject {\n    public function toArray(\$request): array\n    {\n{$body}\n    }\n}\n");

        $source = ParsedSource::forFile($path);
        $this->assertNotNull($source);

        $namespace = preg_match('/namespace\s+([^;{\s]+)/', $header, $m) === 1 ? $m[1].'\\' : '';
        $method = $source->methodNode($namespace.'Subject', 'toArray');
        $this->assertNotNull($method);

        return $method;
    }

    /**
     * @param  list<string>  $wrappers
     * @return list<ReturnedArray>
     */
    private function returns(string $body, array $wrappers = [], string $header = "namespace App\\Http\\Resources;\n"): array
    {
        return new ReturnedArrayReader($wrappers)->returns($this->method($body, $header));
    }

    /** @param list<string> $wrappers */
    private function single(string $body, array $wrappers = [], string $header = "namespace App\\Http\\Resources;\n"): ReturnedArray
    {
        $returns = $this->returns($body, $wrappers, $header);
        $this->assertCount(1, $returns);

        return $returns[0];
    }

    /**
     * Compact view of the parts: kind:key[?] for items, kind:combine[?] for exprs.
     *
     * @return list<string>
     */
    private function shape(ReturnedArray $array): array
    {
        return array_map(static fn (ReturnedPart $part): string => match ($part->kind) {
            'item' => 'item:'.$part->key.($part->conditional ? '?' : ''),
            'dynamic' => 'dynamic'.($part->conditional ? '?' : ''),
            'expr' => 'expr:'.$part->combine.($part->conditional ? '?' : ''),
            default => 'unknown:'.$part->kind,
        }, $array->parts);
    }

    /**
     * Compact view of keys(): `key` or `key?` when conditional.
     *
     * @return list<string>
     */
    private function keyList(ReturnedArray $array): array
    {
        return array_map(
            static fn (array $key): string => $key['key'].($key['conditional'] ? '?' : ''),
            $array->keys(),
        );
    }

    public function test_literal_keys_are_items_in_source_order(): void
    {
        $array = $this->single("return ['id' => \$this->id, \"name\" => \$this->name];");

        $this->assertSame(['item:id', 'item:name'], $this->shape($array));
        $this->assertFalse($array->isOpen());
        $this->assertSame(['id', 'name'], $this->keyList($array));
        $this->assertInstanceOf(PropertyFetch::class, $array->parts[0]->value);
        $this->assertNull($array->parts[0]->expr);
    }

    public function test_non_literal_key_is_dynamic_and_opens_the_array(): void
    {
        $array = $this->single("return [\$key => 1, self::KEY => 2, 'a' => 3];");

        $this->assertSame(['dynamic', 'dynamic', 'item:a'], $this->shape($array));
        $this->assertNull($array->parts[0]->key);
        $this->assertInstanceOf(Expr::class, $array->parts[0]->value);
        $this->assertTrue($array->isOpen());
        $this->assertSame(['a'], $this->keyList($array));
    }

    public function test_item_without_key_is_dynamic(): void
    {
        $array = $this->single("return [\$this->id, 'a' => 1];");

        $this->assertSame(['dynamic', 'item:a'], $this->shape($array));
        $this->assertTrue($array->isOpen());
    }

    public function test_spread_is_an_expr_part(): void
    {
        $array = $this->single("return [...\$extra, 'a' => 1, ...parent::toArray(\$request)];");

        $this->assertSame(['expr:spread', 'item:a', 'expr:spread'], $this->shape($array));
        $this->assertInstanceOf(Variable::class, $array->parts[0]->expr);
        $this->assertInstanceOf(StaticCall::class, $array->parts[2]->expr);
        $this->assertNull($array->parts[0]->value);
        $this->assertTrue($array->isOpen());
        $this->assertSame(['a'], $this->keyList($array));
    }

    public function test_spread_of_a_literal_array_is_inlined(): void
    {
        $array = $this->single("return ['a' => 1, ...['b' => 2]];");

        $this->assertSame(['item:a', 'item:b'], $this->shape($array));
        $this->assertFalse($array->isOpen());
    }

    public function test_merge_items_are_inlined_unconditionally(): void
    {
        $array = $this->single(<<<'PHP'
        return [
            'a' => 1,
            $this->merge(['b' => 2, 'c' => $this->whenLoaded('c')]),
            'd' => 3,
        ];
        PHP);

        $this->assertSame(['item:a', 'item:b', 'item:c?', 'item:d'], $this->shape($array));
        $this->assertFalse($array->isOpen());
        $this->assertSame(['a', 'b', 'c?', 'd'], $this->keyList($array));
    }

    public function test_merge_when_items_are_inlined_as_conditional(): void
    {
        $array = $this->single("return ['a' => 1, \$this->mergeWhen(\$request->user(), ['b' => 2, 'c' => 3])];");

        $this->assertSame(['item:a', 'item:b?', 'item:c?'], $this->shape($array));
        $this->assertFalse($array->isOpen());
        $this->assertSame(['a', 'b?', 'c?'], $this->keyList($array));
    }

    public function test_merge_with_non_literal_argument_is_an_expr(): void
    {
        $array = $this->single("return ['a' => 1, \$this->merge(\$extra), \$this->mergeWhen(\$c, \$this->extra())];");

        $this->assertSame(['item:a', 'expr:merge', 'expr:merge?'], $this->shape($array));
        $this->assertInstanceOf(Variable::class, $array->parts[1]->expr);
        $this->assertTrue($array->isOpen());
    }

    public function test_merge_when_with_default_is_conditional_only_where_the_branches_differ(): void
    {
        $array = $this->single("return [\$this->mergeWhen(\$c, ['a' => 1, 'b' => 2], ['a' => 0, 'z' => 9])];");

        $this->assertSame(['item:a', 'item:b?', 'item:a', 'item:z?'], $this->shape($array));
        $this->assertSame(['a', 'b?', 'z?'], $this->keyList($array));

        $named = $this->single("return [\$this->mergeWhen(\$c, ['a' => 1], default: ['a' => 0])];");
        $this->assertSame(['a'], $this->keyList($named));
    }

    public function test_merge_when_nested_in_merge_when_stays_conditional(): void
    {
        $array = $this->single("return [\$this->mergeWhen(\$a, [\$this->mergeWhen(\$b, ['x' => 1], ['x' => 0]), \$this->merge(['y' => 1])])];");

        $this->assertSame(['item:x?', 'item:x?', 'item:y?'], $this->shape($array));
    }

    public function test_merge_when_with_opaque_branch_keeps_literal_items_unconditional_and_marks_exprs_conditional(): void
    {
        $array = $this->single("return [\$this->mergeWhen(\$c, ['a' => 1], \$fallback)];");

        $this->assertSame(['item:a', 'expr:merge?'], $this->shape($array));
        $this->assertTrue($array->isOpen());
        $this->assertSame(['a'], $this->keyList($array));

        $opaque = $this->single('return [$this->mergeWhen($c, $this->a(), $this->b())];');
        $this->assertSame(['expr:merge?', 'expr:merge?'], $this->shape($opaque));
    }

    public function test_merge_on_another_object_is_not_a_resource_merge(): void
    {
        $array = $this->single("return [\$other->merge(['b' => 2])];");

        $this->assertSame(['dynamic'], $this->shape($array));
    }

    public function test_array_merge_decomposes_each_argument_in_order(): void
    {
        $array = $this->single("return array_merge(parent::toArray(\$request), ['x' => 1]);");

        $this->assertSame(['expr:merge', 'item:x'], $this->shape($array));
        $this->assertInstanceOf(StaticCall::class, $array->parts[0]->expr);
        $this->assertTrue($array->isOpen());
        $this->assertSame(['x'], $this->keyList($array));
    }

    public function test_array_merge_matches_case_insensitively_and_fully_qualified(): void
    {
        $upper = $this->single("return ARRAY_MERGE(['a' => 1], ['b' => 2]);");
        $this->assertSame(['item:a', 'item:b'], $this->shape($upper));
        $this->assertFalse($upper->isOpen());

        $qualified = $this->single("return \\array_merge(['a' => 1], array_merge(['b' => 2], \$rest), ...\$more);");
        $this->assertSame(['item:a', 'item:b', 'expr:merge', 'expr:merge'], $this->shape($qualified));
    }

    public function test_namespaced_lookalike_of_array_merge_is_opaque(): void
    {
        $array = $this->single("return Helpers\\array_merge(['a' => 1]);");

        $this->assertSame(['expr:whole'], $this->shape($array));
        $this->assertSame(['expr:whole'], $this->shape($this->single('return array_merge(...);')));
    }

    public function test_plus_decomposes_both_operands(): void
    {
        $array = $this->single("return ['a' => 1] + \$defaults + ['b' => 2];");

        $this->assertSame(['item:a', 'expr:plus', 'item:b'], $this->shape($array));
        $this->assertTrue($array->isOpen());
        $this->assertSame(['a', 'b'], $this->keyList($array));
    }

    public function test_anything_else_is_a_single_whole_expr(): void
    {
        $array = $this->single('return parent::toArray($request);');

        $this->assertSame(['expr:whole'], $this->shape($array));
        $this->assertInstanceOf(StaticCall::class, $array->parts[0]->expr);
        $this->assertTrue($array->isOpen());
        $this->assertSame([], $this->keyList($array));
    }

    public function test_when_loaded_is_conditional(): void
    {
        $array = $this->single("return ['posts' => \$this->whenLoaded('posts'), 'id' => \$this->id];");

        $this->assertSame(['posts?', 'id'], $this->keyList($array));
    }

    public function test_when_loaded_with_default_is_unconditional(): void
    {
        $array = $this->single(<<<'PHP'
        return [
            'a' => $this->whenLoaded('a', fn () => 1, []),
            'b' => $this->whenLoaded('b', default: []),
            'c' => $this->WHENLOADED('c'),
        ];
        PHP);

        $this->assertSame(['a', 'b', 'c?'], $this->keyList($array));
    }

    public function test_when_not_null_with_null_default_is_unconditional(): void
    {
        $array = $this->single("return ['a' => \$this->whenNotNull(\$this->a, null), 'b' => \$this->whenNotNull(\$this->b)];");

        $this->assertSame(['a', 'b?'], $this->keyList($array));
    }

    /**
     * Every wrapper of the closed list, with arguments filled up to (but not
     * including) its `$default` position, and then with the default supplied.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function whenFamily(): iterable
    {
        yield 'when' => ['when($c, 1)', 'when($c, 1, 0)'];
        yield 'whenLoaded' => ["whenLoaded('r', 1)", "whenLoaded('r', 1, 0)"];
        yield 'whenCounted' => ["whenCounted('r', 1)", "whenCounted('r', 1, 0)"];
        yield 'whenHas' => ["whenHas('a', 1)", "whenHas('a', 1, 0)"];
        yield 'whenNotNull' => ['whenNotNull($v)', 'whenNotNull($v, 0)'];
        yield 'whenAggregated' => ["whenAggregated('r', 'c', 'sum', 1)", "whenAggregated('r', 'c', 'sum', 1, 0)"];
        yield 'whenPivotLoaded' => ["whenPivotLoaded('t', 1)", "whenPivotLoaded('t', 1, 0)"];
        yield 'whenPivotLoadedAs' => ["whenPivotLoadedAs('p', 't', 1)", "whenPivotLoadedAs('p', 't', 1, 0)"];
        yield 'whenExistsLoaded' => ["whenExistsLoaded('r', 1)", "whenExistsLoaded('r', 1, 0)"];
    }

    #[DataProvider('whenFamily')]
    public function test_when_family_is_conditional_unless_a_default_is_given(string $without, string $with): void
    {
        $array = $this->single("return ['without' => \$this->{$without}, 'with' => \$this->{$with}];");

        $this->assertSame(['without?', 'with'], $this->keyList($array));
    }

    public function test_methods_outside_the_closed_list_and_unknown_arguments_are_unconditional(): void
    {
        $array = $this->single(<<<'PHP'
        return [
            'a' => $this->whenNull($this->a),
            'b' => $this->unless($c, 1),
            'c' => $other->whenLoaded('c'),
            'd' => $this->whenLoaded(...$args),
            'e' => $this->whenLoaded(...),
        ];
        PHP);

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $this->keyList($array));
    }

    public function test_resource_collection_of_a_conditional_value_is_conditional(): void
    {
        $array = $this->single(<<<'PHP'
        return [
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            'made' => PostResource::make($this->whenLoaded('made')),
            'plain' => PostResource::collection($this->posts),
            'defaulted' => PostResource::collection($this->whenLoaded('d', default: [])),
            'other' => PostResource::other($this->whenLoaded('o')),
        ];
        PHP);

        $this->assertSame(
            ['posts?', 'made?', 'plain', 'defaulted', 'other'],
            $this->keyList($array),
        );
    }

    public function test_new_resource_of_a_conditional_value_is_conditional(): void
    {
        $array = $this->single("return ['p' => new PostResource(\$this->whenLoaded('p')), 'q' => new PostResource(\$this->q)];");

        $this->assertSame(['p?', 'q'], $this->keyList($array));
    }

    public function test_each_return_is_its_own_array(): void
    {
        $returns = $this->returns(<<<'PHP'
        if ($this->admin) {
            return ['a' => 1, 'b' => 2];
        } else {
            return ['a' => 1];
        }
        PHP);

        $this->assertCount(2, $returns);
        $this->assertSame(['a', 'b'], $this->keyList($returns[0]));
        $this->assertSame(['a'], $this->keyList($returns[1]));
    }

    public function test_returns_in_closures_arrow_functions_and_anonymous_classes_are_ignored(): void
    {
        $returns = $this->returns(<<<'PHP'
        $a = function () { return ['closure' => 1]; };
        $b = fn () => ['arrow' => 1];
        $c = new class { public function x() { return ['anon' => 1]; } };
        foreach ([1] as $i) {
            return ['loop' => 1];
        }
        return;
        PHP);

        $this->assertCount(1, $returns);
        $this->assertSame(['loop'], $this->keyList($returns[0]));
    }

    public function test_method_without_body_has_no_returns(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'returnedarray');
        $this->files[] = $path;
        file_put_contents($path, "<?php\nabstract class Abstracted { abstract public function toArray(\$r): array; }\n");

        $method = ParsedSource::forFile($path)?->methodNode('Abstracted', 'toArray');
        $this->assertNotNull($method);
        $this->assertSame([], new ReturnedArrayReader()->returns($method));
    }

    public function test_keys_seen_both_conditionally_and_not_are_unconditional(): void
    {
        $array = $this->single(<<<'PHP'
        return [
            'a' => $this->whenLoaded('a'),
            'b' => $this->whenLoaded('b'),
            $this->merge(['a' => 1]),
            $this->mergeWhen($c, ['c' => 1]),
            'c' => $this->whenLoaded('c'),
        ];
        PHP);

        $this->assertSame(['a', 'b?', 'c?'], $this->keyList($array));
    }

    public function test_read_decomposes_a_single_expression(): void
    {
        $method = $this->method("return ['a' => 1, ...\$b];");
        $returns = new ReturnedArrayReader()->returns($method);
        $expr = $returns[0]->parts[0]->value;
        $this->assertInstanceOf(Expr::class, $expr);

        $whole = new ReturnedArrayReader()->read($expr);
        $this->assertSame(['expr:whole'], $this->shape($whole));
    }

    public function test_configured_conditional_wrappers_mark_values_conditional(): void
    {
        $wrappers = ['Inertia\Inertia::optional', 'Inertia\Inertia::defer'];
        $header = "namespace App\\Http\\Controllers;\nuse Inertia\\Inertia;\n";
        $body = <<<'PHP'
        return [
            'a' => Inertia::optional(fn () => 1),
            'b' => Inertia::DEFER(fn () => 1),
            'c' => Inertia::always(fn () => 1),
            'd' => \Inertia\Inertia::optional(fn () => 1),
            'e' => Other::optional(fn () => 1),
        ];
        PHP;

        $this->assertSame(
            ['a?', 'b?', 'c', 'd?', 'e'],
            $this->keyList($this->single($body, $wrappers, $header)),
        );
        $this->assertSame(
            ['a', 'b', 'c', 'd', 'e'],
            $this->keyList($this->single($body, [], $header)),
        );
    }

    public function test_wrapper_class_matches_its_fqcn_or_the_global_alias_only(): void
    {
        $wrappers = ['Inertia\Inertia::optional'];
        $body = "return ['a' => Inertia::optional(fn () => 1)];";

        $this->assertSame(['a?'], $this->keyList($this->single($body, $wrappers, '')), 'global namespace: the alias itself');
        $this->assertSame(['a?'], $this->keyList($this->single($body, $wrappers, "namespace App\\Http\\Controllers;\nuse Inertia;\n")));
        $this->assertSame(['a?'], $this->keyList($this->single("return ['a' => \\Inertia::optional(fn () => 1)];", $wrappers)));
        $this->assertSame(
            ['a'],
            $this->keyList($this->single($body, $wrappers, "namespace App\\Http\\Controllers;\n")),
            'unqualified and unimported inside a namespace resolves to App\\Http\\Controllers\\Inertia',
        );
        $this->assertSame(['a'], $this->keyList($this->single($body, $wrappers, "namespace App\\Http\\Controllers;\nuse Vendor\\Inertia;\n")));
        $this->assertSame(['a'], $this->keyList($this->single("return ['a' => Sub\\Inertia::optional(fn () => 1)];", $wrappers, "namespace App;\n")));
    }

    public function test_refers_to_matches_the_resolved_fqcn_and_the_global_alias(): void
    {
        $method = $this->method(
            "return [Inertia::render('A'), Facade::x(), Vendor\\Inertia::y(), \\Inertia::z()];",
            "namespace App\\Http\\Controllers;\nuse Inertia\\Inertia as Facade;\n",
        );
        $parts = new ReturnedArrayReader()->returns($method)[0]->parts;
        $names = array_map(static function (ReturnedPart $part): Name {
            $value = $part->value;
            self::assertInstanceOf(StaticCall::class, $value);
            self::assertInstanceOf(Name::class, $value->class);

            return $value->class;
        }, $parts);

        $this->assertFalse(ReturnedArrayReader::refersTo($names[0], 'Inertia\Inertia'), 'unimported in a namespace');
        $this->assertTrue(ReturnedArrayReader::refersTo($names[1], 'inertia\INERTIA'));
        $this->assertFalse(ReturnedArrayReader::refersTo($names[2], 'Inertia\Inertia'));
        $this->assertTrue(ReturnedArrayReader::refersTo($names[3], 'Inertia'));
        $this->assertTrue(ReturnedArrayReader::refersTo($names[3], 'Inertia\Inertia'), 'the global alias itself');
    }

    public function test_keys_returns_string_keys_in_first_seen_order(): void
    {
        $array = $this->single("return ['0' => 1, '123' => \$this->whenLoaded('x'), 'a' => 1, '123' => 2];");

        $this->assertSame([
            ['key' => '0', 'conditional' => false],
            ['key' => '123', 'conditional' => false],
            ['key' => 'a', 'conditional' => false],
        ], $array->keys());
        $this->assertSame([['key' => 'b', 'conditional' => true]], $this->single("return ['b' => \$this->whenLoaded('b')];")->keys());
    }

    public function test_conditional_collection_make_and_new_require_a_json_resource_class(): void
    {
        $array = $this->single(<<<'PHP'
        return [
            'collection' => new \Illuminate\Support\Collection($this->whenLoaded('x')),
            'made' => \Illuminate\Support\Collection::make($this->whenLoaded('y')),
            'missing' => Missing::collection($this->whenLoaded('z')),
            'resource' => new \App\Http\Resources\PostResource($this->whenLoaded('p')),
            'static' => static::collection($this->whenLoaded('s')),
        ];
        PHP);

        $this->assertSame(['collection', 'made', 'missing', 'resource?', 'static'], $this->keyList($array));
    }

    public function test_read_decomposes_an_array_literal_with_mixed_items(): void
    {
        $statements = new ParserFactory()->createForHostVersion()->parse(
            "<?php ['a' => 1, \$k => 2, 3, ...\$x, \$this->merge(['b' => 1]), \$this->mergeWhen(\$c, ['c' => 1]), 'd' => \$this->whenLoaded('d')];",
        );
        $statement = $statements[0] ?? null;
        $this->assertInstanceOf(Expression::class, $statement);

        $array = new ReturnedArrayReader()->read($statement->expr);

        $this->assertSame(['item:a', 'dynamic', 'dynamic', 'expr:spread', 'item:b', 'item:c?', 'item:d?'], $this->shape($array));
        $this->assertTrue($array->isOpen());
        $this->assertSame(['a', 'b', 'c?', 'd?'], $this->keyList($array));
    }
}
