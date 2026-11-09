<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Pentacore\Typefinder\Resolvers\PhpDocScope;
use Pentacore\Typefinder\Resolvers\PhpDocTypeMapper;
use Pentacore\Typefinder\Support\GeneratedClasses;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Fixtures\Resources\PhpDoc\PureStatus;
use Tests\TestCase;

final class PhpDocTypeMapperTest extends TestCase
{
    private const string SELF = 'App\Http\Resources\SelfResource';

    /** Short names the test "file" imports, as a declaring file's `use` list would. */
    private const array NAMES = [
        'User' => User::class,
        'PostStatus' => PostStatus::class,
        'UserResource' => UserResource::class,
        'PostResource' => PostResource::class,
        'Carbon' => Carbon::class,
        'CarbonImmutable' => CarbonImmutable::class,
        'DateTimeImmutable' => DateTimeImmutable::class,
        'Collection' => Collection::class,
        'EloquentCollection' => EloquentCollection::class,
        'AnonymousResourceCollection' => AnonymousResourceCollection::class,
        'MissingValue' => MissingValue::class,
        'SomePureEnum' => PureStatus::class,
        'stdClass' => stdClass::class,
    ];

    /** @var list<string> */
    private array $warnings = [];

    private PhpDocTypeMapper $phpDocTypeMapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warnings = [];
        $this->phpDocTypeMapper = new PhpDocTypeMapper(
            new GeneratedClasses(
                models: [User::class],
                enums: [PostStatus::class],
                resources: [UserResource::class, PostResource::class],
            ),
            function (string $message): void {
                $this->warnings[] = $message;
            },
        );
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function scalarTypes(): array
    {
        $number = self::ts('number');
        $string = self::ts('string');

        return [
            'int' => ['int', $number],
            'integer' => ['integer', $number],
            'positive-int' => ['positive-int', $number],
            'negative-int' => ['negative-int', $number],
            'non-negative-int' => ['non-negative-int', $number],
            'non-positive-int' => ['non-positive-int', $number],
            'non-zero-int' => ['non-zero-int', $number],
            'int range' => ['int<0, max>', $number],
            'float' => ['float', $number],
            'double' => ['double', $number],
            'numeric' => ['numeric', self::ts('number | string')],
            'string' => ['string', $string],
            'non-empty-string' => ['non-empty-string', $string],
            'non-falsy-string' => ['non-falsy-string', $string],
            'truthy-string' => ['truthy-string', $string],
            'lowercase-string' => ['lowercase-string', $string],
            'uppercase-string' => ['uppercase-string', $string],
            'literal-string' => ['literal-string', $string],
            'numeric-string' => ['numeric-string', $string],
            'class-string' => ['class-string', $string],
            'class-string generic' => ['class-string<Foo>', $string],
            'callable-string' => ['callable-string', $string],
            'non-empty-lowercase-string' => ['non-empty-lowercase-string', $string],
            'bool' => ['bool', self::ts('boolean')],
            'boolean' => ['boolean', self::ts('boolean')],
            'true' => ['true', self::ts('true')],
            'false' => ['false', self::ts('false')],
            'null' => ['null', self::ts('null')],
            'keyword case' => ['Int', $number],
            'single-quoted literal' => ["'draft'", self::ts("'draft'")],
            'double-quoted literal' => ['"x"', self::ts("'x'")],
            'literal with quote' => ['"it\'s"', self::ts("'it\\'s'")],
            'int literal' => ['42', self::ts('42')],
            'float literal' => ['1.5', self::ts('1.5')],
            'mixed' => ['mixed', self::ts('unknown')],
            'array-key' => ['array-key', self::ts('string | number')],
            'scalar' => ['scalar', self::ts('string | number | boolean')],
            'object' => ['object', self::ts('Record<string, unknown>')],
            'nested array' => ['array', self::ts('Record<string, unknown>')],
            'array of mixed' => ['array<mixed>', self::ts('Record<string, unknown>')],
            'bare iterable' => ['iterable', self::ts('Record<string, unknown>')],
            'Carbon' => ['Carbon', $string],
            'CarbonImmutable' => ['CarbonImmutable', $string],
            'DateTimeInterface' => ['\DateTimeInterface', $string],
            'DateTimeImmutable' => ['DateTimeImmutable', $string],
            'stdClass' => ['stdClass', self::ts('Record<string, unknown>')],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('scalarTypes')]
    public function test_maps_scalar_and_literal_types(string $type, array $expected): void
    {
        $this->assertSame($expected, $this->map($type));
        $this->assertSame([], $this->warnings);
    }

    public function test_literal_union(): void
    {
        $this->assertSame(
            ['kind' => 'union', 'of' => [self::ts("'draft'"), self::ts("'published'")]],
            $this->map("'draft'|'published'"),
        );
    }

    public function test_nullable_becomes_union_with_null(): void
    {
        $this->assertSame(['kind' => 'union', 'of' => [self::ts('number'), self::ts('null')]], $this->map('?int'));
    }

    public function test_union_and_intersection_keep_source_order(): void
    {
        $this->assertSame(
            ['kind' => 'union', 'of' => [self::ts('string'), self::ts('number'), self::ts('null')]],
            $this->map('string|int|null'),
        );
        $this->assertSame(
            ['kind' => 'intersection', 'of' => [$this->model(User::class), self::ts('Record<string, unknown>')]],
            $this->map('User&object'),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listTypes(): array
    {
        return [
            'list' => ['list<int>'],
            'non-empty-list' => ['non-empty-list<int>'],
            'suffix' => ['int[]'],
            'array one arg' => ['array<int>'],
            'array int key' => ['array<int, int>'],
            'non-empty-array int key' => ['non-empty-array<int, int>'],
            'iterable one arg' => ['iterable<int>'],
            'iterable int key' => ['iterable<int, int>'],
        ];
    }

    #[DataProvider('listTypes')]
    public function test_list_types_map_to_arrays(string $type): void
    {
        $this->assertSame($this->arrayOf(self::ts('number')), $this->map($type));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function recordTypes(): array
    {
        return [
            'string key' => ['array<string, float>'],
            'non-empty string key' => ['non-empty-array<string, float>'],
            'array-key' => ['array<array-key, float>'],
        ];
    }

    #[DataProvider('recordTypes')]
    public function test_string_keyed_arrays_map_to_records(string $type): void
    {
        $this->assertSame(['kind' => 'record', 'of' => self::ts('number')], $this->map($type));
    }

    public function test_array_shape_maps_to_object(): void
    {
        $this->assertSame(
            ['kind' => 'object', 'fields' => [
                ['name' => 'a', 'type' => self::ts('number'), 'optional' => false],
                ['name' => 'b', 'type' => self::ts('string'), 'optional' => true],
            ], 'open' => false],
            $this->map('array{a: int, b?: string}'),
        );
    }

    public function test_unsealed_shape_is_open(): void
    {
        $this->assertSame(
            ['kind' => 'object', 'fields' => [
                ['name' => 'a', 'type' => self::ts('number'), 'optional' => false],
            ], 'open' => true],
            $this->map('array{a: int, ...}'),
        );
    }

    public function test_quoted_and_numeric_keys_keep_their_names(): void
    {
        $node = $this->map("array{'foo-bar': int, \"baz\": string, 0: bool}");

        $this->assertSame(['foo-bar', 'baz', '0'], array_column($node['fields'], 'name'));
    }

    public function test_list_shape_maps_to_tuple(): void
    {
        $tuple = ['kind' => 'tuple', 'items' => [
            ['type' => self::ts('number'), 'optional' => false],
            ['type' => self::ts('string'), 'optional' => false],
        ]];

        $this->assertSame($tuple, $this->map('array{int, string}'));
        $this->assertSame($tuple, $this->map('list{int, string}'));

        $tuple['items'][1]['optional'] = true;
        $this->assertSame($tuple, $this->map('array{int, 1?: string}'));
    }

    public function test_object_shape_maps_to_object(): void
    {
        $this->assertSame(
            ['kind' => 'object', 'fields' => [
                ['name' => 'a', 'type' => self::ts('number'), 'optional' => false],
            ], 'open' => false],
            $this->map('object{a: int}'),
        );
    }

    public function test_nested_shapes_map_recursively(): void
    {
        $this->assertSame(
            ['kind' => 'object', 'fields' => [
                ['name' => 'meta', 'type' => ['kind' => 'object', 'fields' => [
                    ['name' => 'total', 'type' => self::ts('number'), 'optional' => false],
                    ['name' => 'tags', 'type' => $this->arrayOf(self::ts('string')), 'optional' => false],
                ], 'open' => false], 'optional' => false],
            ], 'open' => false],
            $this->map('array{meta: array{total: int, tags: list<string>}}'),
        );
    }

    public function test_classifies_generated_classes(): void
    {
        $this->assertSame($this->resource(UserResource::class), $this->map('UserResource'));
        $this->assertSame($this->model(User::class), $this->map('User'));
        $this->assertSame(['kind' => 'enum', 'fqcn' => PostStatus::class], $this->map('PostStatus'));
        $this->assertSame($this->model(User::class), $this->map('\\'.User::class));
        $this->assertSame([], $this->warnings);
    }

    public function test_self_references_resolve_to_the_resource_being_generated(): void
    {
        $this->assertSame($this->resource(self::SELF), $this->map('static'));
        $this->assertSame($this->resource(self::SELF), $this->map('self'));
        $this->assertSame($this->resource(self::SELF), $this->map('$this'));
        $this->assertSame([], $this->warnings);
    }

    public function test_collections_use_their_last_generic_argument(): void
    {
        $this->assertSame($this->arrayOf($this->resource(PostResource::class)), $this->map('Collection<int, PostResource>'));
        $this->assertSame($this->arrayOf($this->model(User::class)), $this->map('EloquentCollection<int, User>'));
        $this->assertSame($this->arrayOf($this->model(User::class)), $this->map('Collection<User>'));
        $this->assertSame(
            $this->arrayOf($this->resource(UserResource::class)),
            $this->map('AnonymousResourceCollection<int, UserResource>'),
        );
        $this->assertSame(['kind' => 'record', 'of' => $this->model(User::class)], $this->map('Collection<string, User>'));
        $this->assertSame([], $this->warnings);
    }

    public function test_bare_collection_is_unknown_array_with_warning(): void
    {
        $this->assertSame($this->arrayOf(self::ts('unknown')), $this->map('Collection', 'tags'));
        $this->assertSame([$this->warning('tags', 'Collection')], $this->warnings);
    }

    public function test_missing_value_member_makes_field_optional(): void
    {
        $this->assertSame(
            ['kind' => 'object', 'fields' => [
                ['name' => 'posts', 'type' => $this->arrayOf($this->resource(PostResource::class)), 'optional' => true],
                ['name' => 'owner', 'type' => ['kind' => 'union', 'of' => [$this->model(User::class), self::ts('null')]], 'optional' => true],
            ], 'open' => false],
            $this->map('array{posts: PostResource[]|MissingValue, owner: User|null|MissingValue}'),
        );
        $this->assertSame([], $this->warnings);
    }

    public function test_unsupported_types_warn_once_each(): void
    {
        $node = $this->map(
            'array{a: callable, b: key-of<X>, c: NoSuchClass, d: \Illuminate\Notifications\DatabaseNotification, e: SomePureEnum}',
        );

        $this->assertSame(
            array_fill(0, 5, self::ts('unknown')),
            array_column($node['fields'], 'type'),
        );
        $this->assertSame([
            $this->warning('a', 'callable'),
            $this->warning('b', 'key-of<X>'),
            $this->warning('c', 'NoSuchClass'),
            $this->warning('d', '\\'.DatabaseNotification::class),
            $this->warning('e', 'SomePureEnum'),
        ], $this->warnings);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedTypes(): array
    {
        return [
            'void' => ['void'],
            'never' => ['never'],
            'Closure' => ['Closure'],
            'resource' => ['resource'],
            'conditional' => ['($x is int ? int : string)'],
            'value-of' => ['value-of<X>'],
            'int-mask' => ['int-mask<1, 2>'],
            'class constant' => ['Foo::BAR'],
            'callable signature' => ['callable(int): void'],
            'offset access' => ["Foo['bar']"],
            'unknown generic' => ['Foo<int>'],
            'missing value outside a field union' => ['MissingValue'],
        ];
    }

    #[DataProvider('unsupportedTypes')]
    public function test_unsupported_type_is_unknown_with_warning(string $type): void
    {
        $this->assertSame(self::ts('unknown'), $this->map($type, 'field'));
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('"field" has unsupported type', $this->warnings[0]);
    }

    public function test_warning_paths_are_dot_joined(): void
    {
        $this->map('array{meta: array{total: callable}}');

        $this->assertSame([$this->warning('meta.total', 'callable')], $this->warnings);
    }

    public function test_aliases_expand_inline(): void
    {
        $scope = $this->scope(['Meta' => ['type' => $this->parse('array{total: int}'), 'scope' => null]]);

        $this->assertSame(
            ['kind' => 'object', 'fields' => [
                ['name' => 'meta', 'type' => ['kind' => 'object', 'fields' => [
                    ['name' => 'total', 'type' => self::ts('number'), 'optional' => false],
                ], 'open' => false], 'optional' => false],
            ], 'open' => false],
            $this->phpDocTypeMapper->map($this->parse('array{meta: Meta}'), $scope, ''),
        );
    }

    public function test_alias_resolves_names_in_its_own_scope(): void
    {
        $foreign = new PhpDocScope(
            self::SELF,
            self::SELF,
            static fn (string $name): string => $name === 'Owner' ? User::class : $name,
        );
        $scope = $this->scope(['Addr' => ['type' => $this->parse('array{owner: Owner}'), 'scope' => $foreign]]);

        $node = $this->phpDocTypeMapper->map($this->parse('Addr'), $scope, '');

        $this->assertSame($this->model(User::class), $node['fields'][0]['type']);
    }

    public function test_alias_cycle_terminates_with_unknown(): void
    {
        $scope = $this->scope([
            'A' => ['type' => $this->parse('B'), 'scope' => null],
            'B' => ['type' => $this->parse('A'), 'scope' => null],
        ]);

        $this->assertSame(self::ts('unknown'), $this->phpDocTypeMapper->map($this->parse('A'), $scope, 'x'));
        $this->assertSame([$this->warning('x', 'A')], $this->warnings);
    }

    public function test_invalid_identifier_does_not_throw(): void
    {
        $scope = new PhpDocScope(
            self::SELF,
            self::SELF,
            static fn (string $name): string => throw new \InvalidArgumentException('bad name'),
        );

        $this->assertSame(self::ts('unknown'), $this->phpDocTypeMapper->map($this->parse('Foo'), $scope, 'x'));
        $this->assertSame([$this->warning('x', 'Foo')], $this->warnings);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(string $type, string $fieldPath = ''): array
    {
        return $this->phpDocTypeMapper->map($this->parse($type), $this->scope(), $fieldPath);
    }

    /**
     * @param  array<string, array{type: TypeNode, scope: ?PhpDocScope}>  $aliases
     */
    private function scope(array $aliases = []): PhpDocScope
    {
        return new PhpDocScope(
            self::SELF,
            self::SELF,
            static fn (string $name): string => self::NAMES[$name] ?? ltrim($name, '\\'),
            $aliases,
        );
    }

    private function parse(string $type): TypeNode
    {
        $parserConfig = new ParserConfig([]);
        $lexer = new Lexer($parserConfig);
        $typeParser = new TypeParser($parserConfig, new ConstExprParser($parserConfig));

        return $typeParser->parse(new TokenIterator($lexer->tokenize($type)));
    }

    private function warning(string $field, string $type): string
    {
        return sprintf('%s::toArray() @return: "%s" has unsupported type %s — emitted as unknown', self::SELF, $field, $type);
    }

    /** @return array{kind: 'ts', ts: string} */
    private static function ts(string $ts): array
    {
        return ['kind' => 'ts', 'ts' => $ts];
    }

    /**
     * @param  array<string, mixed>  $of
     * @return array<string, mixed>
     */
    private function arrayOf(array $of): array
    {
        return ['kind' => 'array', 'of' => $of];
    }

    /** @return array{kind: 'model', fqcn: string} */
    private function model(string $fqcn): array
    {
        return ['kind' => 'model', 'fqcn' => $fqcn];
    }

    /** @return array{kind: 'resource', fqcn: string} */
    private function resource(string $fqcn): array
    {
        return ['kind' => 'resource', 'fqcn' => $fqcn];
    }
}
