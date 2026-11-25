<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use BackedEnum;
use Illuminate\Contracts\Validation\ParameterizedRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rules\AnyOf;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\IpAddress;
use Illuminate\Validation\Rules\Numeric;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\StringRule;
use Pentacore\Typefinder\Analysis\QueryRuleKeys;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Support\ClassDiscovery;
use Pentacore\Typefinder\Support\NullSafeProxy;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use UnitEnum;

use function Illuminate\Support\enum_value;

class RequestExtractor
{
    /**
     * Rules that state the field's type. One of these decides the type,
     * wherever it sits in the list.
     *
     * @var array<string, string>
     */
    protected const PRIMITIVE_RULES = [
        'string' => 'string',
        'integer' => 'number',
        'numeric' => 'number',
        'boolean' => 'boolean',
        'file' => 'File',
        'image' => 'File',
        'array' => self::ARRAY_TYPE,
        'list' => self::LIST_TYPE,
    ];

    /**
     * Rules that check a format and so imply a type, used only when no
     * primitive rule states one (`digits:4` with `string` is a string).
     *
     * @var array<string, string>
     */
    protected const FORMAT_RULES = [
        'alpha' => 'string',
        'alpha_num' => 'string',
        'alpha_dash' => 'string',
        'ascii' => 'string',
        'starts_with' => 'string',
        'ends_with' => 'string',
        'doesnt_start_with' => 'string',
        'doesnt_end_with' => 'string',
        'email' => 'string',
        'url' => 'string',
        'active_url' => 'string',
        'uuid' => 'string',
        'ulid' => 'string',
        'ip' => 'string',
        'ipv4' => 'string',
        'ipv6' => 'string',
        'hex_color' => 'string',
        'mac_address' => 'string',
        'json' => 'string',
        'date' => 'string',
        'date_format' => 'string',
        'timezone' => 'string',
        'lowercase' => 'string',
        'uppercase' => 'string',
        'regex' => 'string',
        'decimal' => 'number',
        'digits' => 'number',
        'digits_between' => 'number',
        'max_digits' => 'number',
        'min_digits' => 'number',
        'accepted_if' => 'boolean',
        'declined_if' => 'boolean',
        'mimes' => 'File',
        'mimetypes' => 'File',
        'extensions' => 'File',
        'dimensions' => 'File',
    ];

    /**
     * `accepted` and `declined` take only these values (as sent on the wire),
     * and must be present.
     *
     * @var array<string, string>
     */
    protected const ACCEPTANCE_RULES = [
        'accepted' => "true | 'yes' | 'on' | 1 | '1' | 'true'",
        'declined' => "false | 'no' | 'off' | 0 | '0' | 'false'",
    ];

    /**
     * Rules that let no value through: the key must be missing (or empty).
     * The conditional `prohibited_if` / `missing_with` keep their other
     * rules' type.
     */
    protected const NEVER_RULES = ['prohibited', 'missing'];

    /**
     * Rule objects that are a `|`-separated string of rules.
     */
    protected const STRINGABLE_RULES = [ArrayRule::class, Date::class, Numeric::class, StringRule::class, IpAddress::class];

    /**
     * At most this many variants of a field's rules (each `Rule::when()`
     * doubles them) are typed; more than that and the field is `unknown`.
     */
    protected const MAX_VARIANTS = 64;

    /**
     * An `array` rule accepts both lists and keyed arrays.
     */
    protected const ARRAY_TYPE = 'unknown[] | Record<string, unknown>';

    protected const LIST_TYPE = 'unknown[]';

    /**
     * What the rules of the request being extracted could not express.
     *
     * @var list<string>
     */
    protected array $warnings = [];

    /**
     * Extract type information from a single FormRequest class.
     *
     * @param  class-string<FormRequest>  $requestClass
     * @return array{name: string, fqcn: class-string, fields: list<array>, query?: list<array>, warnings?: list<string>}
     */
    public function extract(string $requestClass): array
    {
        $this->warnings = [];
        $reflectionClass = new ReflectionClass($requestClass);
        $formRequest = $this->createRequestInstance($requestClass);

        try {
            $rules = $this->callRules($formRequest);
        } catch (\Throwable) {
            $this->applyNullSafeResolvers($formRequest);
            try {
                $rules = $this->callRules($formRequest);
            } catch (\Throwable $e2) {
                $fallback = $this->synthesizeFieldsFromOverrides($reflectionClass);
                if ($fallback === null) {
                    throw $e2;
                }

                return [
                    'name' => $reflectionClass->getShortName(),
                    'fqcn' => $requestClass,
                    'fields' => $fallback,
                ];
            }
        }

        $fields = $this->parseRules($rules);

        $overrides = $this->getTypeOverrides($reflectionClass);
        if ($overrides !== []) {
            $fields = array_map(
                fn (array $field): array => isset($overrides[$field['name']])
                    ? ['type' => $overrides[$field['name']]] + $field
                    : $field,
                $fields,
            );
        }

        [$fields, $queryFields] = $this->separateQueryFields($fields, $reflectionClass);

        $result = [
            'name' => $reflectionClass->getShortName(),
            'fqcn' => $requestClass,
            'fields' => $fields,
        ];

        if ($queryFields !== []) {
            $result['query'] = $queryFields;
        }

        // Carried on the result so the generator can report them.
        if ($this->warnings !== []) {
            $result['warnings'] = array_map(
                fn (string $warning): string => $reflectionClass->getShortName().': '.$warning,
                $this->warnings,
            );
        }

        return $result;
    }

    /**
     * The request's rules, as Laravel gets them: `rules()` called through the
     * container (so its parameters are injected), and none without one.
     *
     * @return array<array-key, mixed>
     */
    protected function callRules(FormRequest $formRequest): array
    {
        if (! method_exists($formRequest, 'rules')) {
            return [];
        }

        return (array) app()->call([$formRequest, 'rules']);
    }

    /**
     * Whether a class gets a request type of its own: a form request, not
     * abstract, not `#[TypefinderIgnore]`d.
     *
     * @param  class-string  $requestClass
     */
    public function isEmittable(string $requestClass): bool
    {
        if (! is_subclass_of($requestClass, FormRequest::class)) {
            return false;
        }

        $reflectionClass = new ReflectionClass($requestClass);

        return ! $reflectionClass->isAbstract()
            && $reflectionClass->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) === [];
    }

    /**
     * {@see self::extract()}, or null when the request cannot be extracted:
     * `$onWarn` is told why, so one request never stops the others.
     *
     * @param  class-string<FormRequest>  $requestClass
     * @param  null|callable(string, \Throwable): void  $onWarn
     */
    public function tryExtract(string $requestClass, ?callable $onWarn = null): ?array
    {
        try {
            return $this->extract($requestClass);
        } catch (\Throwable $throwable) {
            if ($onWarn !== null) {
                $onWarn($requestClass, $throwable);
            }

            return null;
        }
    }

    /**
     * Discover and extract all FormRequests from a directory.
     *
     * @return list<array{name: string, fqcn: class-string, fields: list<array>}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null, ?callable $onWarn = null): array
    {
        $results = [];

        foreach (ClassDiscovery::in($path, $onWarn) as $className) {
            if (! $this->isEmittable($className)) {
                continue;
            }

            if ($onExtract !== null) {
                $onExtract($className);
            }

            $entry = $this->tryExtract($className, $onWarn);
            if ($entry !== null) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * Parse all rules into a structured fields array.
     *
     * The dotted rule names are folded into a tree first, so a rule at any
     * depth lands below its own parent: `a.b.*.c` is `a` → `b` → `*` → `c`.
     *
     * @param  array<array-key, mixed>  $rules
     * @return list<array>
     */
    protected function parseRules(array $rules): array
    {
        $root = ['rules' => null, 'children' => []];

        while ($rules !== []) {
            $fieldName = array_key_first($rules);
            $fieldRules = $rules[$fieldName];
            unset($rules[$fieldName]);

            // `Rule::forEach()` returning keyed rules: rules for the keys below.
            if ($fieldRules instanceof NestedRules) {
                $fieldRules = $this->compileNestedRules($fieldRules, (string) $fieldName);

                if (is_array($fieldRules) && ! array_is_list($fieldRules)) {
                    foreach ($fieldRules as $key => $rule) {
                        $rules[$fieldName.'.'.$key] = $rule;
                    }

                    continue;
                }
            }

            $node = &$root;

            foreach ($this->splitFieldName((string) $fieldName) as $segment) {
                $node['children'][$segment] ??= ['rules' => null, 'children' => []];
                $node = &$node['children'][$segment];
            }

            $node['rules'] = $this->normalizeRules($fieldRules);
            unset($node);
        }

        $fields = [];
        $confirmedFields = [];

        foreach ($root['children'] as $segment => $node) {
            $segment = (string) $segment;

            if ($segment === '*') {
                // The request body itself would be a list; a request type is an object.
                foreach ($this->ruleNames('*', $node) as $ruleName) {
                    $this->warnings[] = sprintf(
                        "rule '%s' was left out: a wildcard at the top level makes the request body a list, which a request type cannot express",
                        $ruleName,
                    );
                }

                continue;
            }

            $fields[] = $this->buildField($segment, $node, $segment);

            if ($node['rules'] !== null && $this->hasRule($node['rules'], 'confirmed')) {
                $confirmedFields[$this->segmentName($segment).'_confirmation'] = $this->resolveType($node['rules']);
            }
        }

        // Add auto-generated confirmation fields
        foreach ($confirmedFields as $confirmFieldName => $confirmType) {
            // Only add if not explicitly defined
            if (! isset($root['children'][$confirmFieldName])) {
                $fields[] = [
                    'name' => $confirmFieldName,
                    'type' => $confirmType,
                    'required' => false,
                    'nullable' => false,
                ];
            }
        }

        return $fields;
    }

    /**
     * Take the query-string parameters out of the fields: those whose rule
     * key, or a rule key below them, is tagged `@query`.
     *
     * @param  list<array>  $fields
     * @param  ReflectionClass<object>  $reflectionClass
     * @return array{list<array>, list<array>} the remaining (body) fields and the query fields
     */
    protected function separateQueryFields(array $fields, ReflectionClass $reflectionClass): array
    {
        $tagged = QueryRuleKeys::of($reflectionClass);

        if ($tagged['unreadable'] > 0) {
            $this->warnings[] = sprintf(
                '%d @query %s ignored: only a rule key written as a string literal, or a spread whose keys can be read, can be tagged',
                $tagged['unreadable'],
                $tagged['unreadable'] === 1 ? 'tag was' : 'tags were',
            );
        }

        $queryNames = array_map(
            fn (string $key): string => $this->segmentName($this->splitFieldName($key)[0]),
            $tagged['keys'],
        );

        $isQuery = fn (array $field): bool => in_array($field['name'], $queryNames, true);

        return [
            array_values(array_filter($fields, fn (array $field): bool => ! $isQuery($field))),
            array_values(array_filter($fields, $isQuery)),
        ];
    }

    /**
     * Split a rule name into its segments at every unescaped dot. Segments
     * keep their escapes (`\.`, `\*`), so a literal `\*` key is not confused
     * with the `*` wildcard; segmentName() removes them.
     *
     * @return list<string>
     */
    protected function splitFieldName(string $fieldName): array
    {
        return preg_split('/(?<!\\\\)\./', $fieldName) ?: [$fieldName];
    }

    /**
     * The property name of a segment: Laravel's `\.` and `\*` escapes removed.
     */
    protected function segmentName(string $segment): string
    {
        return str_replace(['\.', '\*'], ['.', '*'], $segment);
    }

    /**
     * Build one field from a node of the rule tree, with its nested shape.
     *
     * @param  array{rules: ?list<mixed>, children: array<array-key, array>}  $node
     * @param  string  $path  dotted rule name of the node, for warnings
     * @return array{name: string, type: mixed, required: bool, nullable: bool, children?: list<array>, arrayDepth?: int}
     */
    protected function buildField(string $segment, array $node, string $path): array
    {
        $rules = $node['rules'] ?? [];
        $shape = $this->resolveShape($node, $path);

        $field = [
            'name' => $this->segmentName($segment),
            'type' => $shape['type'],
            // A parent without a rule of its own must be present when one of its keys must.
            'required' => $node['rules'] === null
                ? $shape['type'] === 'object' && array_any($shape['children'], fn (array $child): bool => $child['required'])
                : $this->isRequired($rules),
            'nullable' => $this->isNullable($rules),
        ];

        if (isset($shape['children'])) {
            $field['children'] = $shape['children'];
        }

        if (($shape['arrayDepth'] ?? 1) > 1) {
            $field['arrayDepth'] = $shape['arrayDepth'];
        }

        return $field;
    }

    /**
     * Resolve the type of a rule-tree node.
     *
     * Returns a plain type, or `object` / `object-array` with `children`.
     * `arrayDepth` counts the array levels around an `object-array`
     * (`items.*.*.name` is 2).
     *
     * @param  array{rules: ?list<mixed>, children: array<array-key, array>}  $node
     * @return array{type: mixed, children?: list<array>, arrayDepth?: int}
     */
    protected function resolveShape(array $node, string $path): array
    {
        $rules = $node['rules'] ?? [];
        $ownType = $this->resolveType($rules);

        // The node's own rule decides, unless it has keys below it and is an
        // array or has no type of its own.
        if ($node['children'] === [] || ($ownType !== 'unknown' && ! $this->isArrayRule($rules))) {
            return ['type' => $ownType];
        }

        $named = $node['children'];
        $wildcard = $named['*'] ?? null;
        unset($named['*']);

        if ($wildcard === null) {
            $children = [];
            foreach ($named as $segment => $child) {
                $children[] = $this->buildField((string) $segment, $child, $path.'.'.$segment);
            }

            return ['type' => 'object', 'children' => $children];
        }

        if ($named !== []) {
            // A list and a keyed object at once has no TypeScript shape. The
            // wildcard wins; say which keys were dropped.
            $this->warnings[] = sprintf(
                "'%s' has rules for both every item ('%s.*') and named keys (%s); the named keys were left out",
                $path,
                $path,
                implode(', ', array_map(fn (int|string $segment): string => $this->segmentName((string) $segment), array_keys($named))),
            );
        }

        $item = $this->resolveShape($wildcard, $path.'.*');

        if (isset($item['children'])) {
            return [
                'type' => 'object-array',
                'children' => $item['children'],
                'arrayDepth' => ($item['arrayDepth'] ?? 0) + 1,
            ];
        }

        return ['type' => $this->arrayOf($item['type'])];
    }

    /**
     * Every rule name at or below a node, for warnings.
     *
     * @param  array{rules: ?list<mixed>, children: array<array-key, array>}  $node
     * @return list<string>
     */
    protected function ruleNames(string $path, array $node): array
    {
        $names = $node['rules'] === null ? [] : [$path];

        foreach ($node['children'] as $segment => $child) {
            $names = [...$names, ...$this->ruleNames($path.'.'.$segment, $child)];
        }

        return $names;
    }

    /**
     * The enum a `Rule::enum()` accepts, with the backing values it is
     * narrowed to by `->only(…)` or `->except(…)`.
     *
     * @return array{enum: class-string, only?: non-empty-list<int|string>, except?: non-empty-list<int|string>}
     */
    protected function resolveEnumRule(Enum $enum): array
    {
        $reflectionClass = new ReflectionClass($enum);
        $type = ['enum' => $reflectionClass->getProperty('type')->getValue($enum)];

        // Like the rule itself: with `only` set, `except` is never looked at.
        $only = (array) $reflectionClass->getProperty('only')->getValue($enum);
        $subset = $only === [] ? 'except' : 'only';
        $cases = $only === [] ? (array) $reflectionClass->getProperty('except')->getValue($enum) : $only;

        $values = [];
        foreach ($cases as $case) {
            if (! $case instanceof BackedEnum) {
                // A pure enum case has no value to narrow the type with.
                return $type;
            }

            $values[] = $case->value;
        }

        return $values === [] ? $type : [...$type, $subset => $values];
    }

    /**
     * Resolve the TypeScript type from a set of rules.
     *
     * Laravel applies every rule of a field, so `Rule::enum()`, `Rule::in()`
     * and `in:` narrow the type wherever they sit in the list. Rules with a
     * `Rule::when()` are typed as the union of its branches.
     *
     * @param  list<mixed>  $rules  normalized ({@see self::normalizeRules()})
     */
    protected function resolveType(array $rules): mixed
    {
        $types = [];

        foreach ($this->variants($rules) as $variant) {
            $type = $this->resolveVariantType($variant);
            $types[serialize($type)] = $type;
        }

        // A branch that states no type (the empty default of `Rule::when()`) adds nothing.
        if (count($types) > 1) {
            unset($types[serialize('unknown')]);
        }

        $types = array_values($types);

        return count($types) === 1 ? $types[0] : ['anyOf' => $types];
    }

    /**
     * The type of one variant of a field's rules: a rule list without
     * `Rule::when()`.
     *
     * @param  list<mixed>  $rules
     */
    protected function resolveVariantType(array $rules): mixed
    {
        // No value gets past `prohibited` or `missing`.
        foreach (self::NEVER_RULES as $never) {
            if (in_array($never, $rules, true)) {
                return 'never';
            }
        }

        foreach ($rules as $rule) {
            if ($rule instanceof Enum) {
                return $this->resolveEnumRule($rule);
            }
        }

        $declared = $this->declaredType($rules);
        $values = $this->inValues($rules);

        if ($values === null) {
            return $declared;
        }

        // On an array, `in` checks every item.
        if ($this->isArrayRule($rules)) {
            $itemType = $this->inType($values, 'unknown');

            return $itemType === null ? $declared : ['arrayOf' => $itemType];
        }

        // Null when the values cannot be written in the declared type (`boolean` with `in:1`).
        return $this->inType($values, $declared) ?? $declared;
    }

    /**
     * The type of the values an `in` rule accepts, on a field of the declared
     * type; null when they cannot be written in it.
     *
     * @param  list<mixed>  $values
     */
    protected function inType(array $values, mixed $declared): mixed
    {
        // The cases of one backed enum: that enum, narrowed to them.
        $first = $values[0] ?? null;
        if ($first instanceof BackedEnum && array_all($values, static fn (mixed $value): bool => $value instanceof $first)) {
            return ['enum' => $first::class, 'only' => array_map(static fn (BackedEnum $case): int|string => $case->value, $values)];
        }

        // What Laravel compares: an enum case by its value (a pure one by its name).
        $values = array_values(array_filter(
            array_map(static fn (mixed $value): mixed => $value instanceof UnitEnum ? enum_value($value) : $value, $values),
            is_scalar(...),
        ));

        if ($declared === 'string') {
            return ['in' => array_values(array_unique(array_map(
                static fn (bool|int|float|string $value): string => is_bool($value) ? ($value ? '1' : '') : (string) $value,
                $values,
            )))];
        }

        if ($declared === 'number') {
            if (! array_all($values, static fn (mixed $value): bool => ! is_bool($value) && is_numeric($value))) {
                return null;
            }

            return ['in' => array_map(static fn (int|float|string $value): int|float => is_string($value) ? $value + 0 : $value, $values)];
        }

        if ($declared !== 'unknown') {
            return null;
        }

        // With no declared type the values keep their own: `Rule::in([1, 2])` is `1 | 2`.
        $bools = array_values(array_filter($values, is_bool(...)));
        $others = array_values(array_filter($values, static fn (mixed $value): bool => ! is_bool($value)));

        $boolType = match (true) {
            $bools === [] => null,
            in_array(true, $bools, true) && in_array(false, $bools, true) => 'boolean',
            default => $bools[0] ? 'true' : 'false',
        };

        if ($boolType === null) {
            return ['in' => $others];
        }

        return $others === [] ? $boolType : ['anyOf' => [['in' => $others], $boolType]];
    }

    /**
     * The values the first `Rule::in()` or `in:` rule accepts, with their
     * own types, or null when the rules have none.
     *
     * @param  list<mixed>  $rules
     * @return ?list<mixed>
     */
    protected function inValues(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if ($rule instanceof In) {
                return array_values((array) new ReflectionProperty($rule, 'values')->getValue($rule));
            }

            // Parsed as Laravel parses rule parameters.
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                return str_getcsv(substr($rule, 3), ',', '"', '\\');
            }
        }

        return null;
    }

    /**
     * The type the rules declare, or `unknown`: `accepted` / `declined`,
     * else the first primitive rule (`string`, `integer`, `array:keys`,
     * `Rule::anyOf()`, …), else the first format rule (`digits`, `email`, …).
     *
     * @param  list<mixed>  $rules
     */
    protected function declaredType(array $rules): mixed
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && isset(self::ACCEPTANCE_RULES[$rule])) {
                return self::ACCEPTANCE_RULES[$rule];
            }
        }

        $format = null;

        foreach ($rules as $rule) {
            // Rule::anyOf([...]) is Laravel 13+ only.
            if (class_exists(AnyOf::class) && $rule instanceof AnyOf) {
                $types = $this->resolveAnyOfTypes($rule);

                if ($types !== []) {
                    return count($types) === 1 ? $types[0] : ['anyOf' => $types];
                }

                continue;
            }

            if (! is_string($rule)) {
                continue;
            }

            // 'array:name,email' — only the listed keys are allowed
            if (str_starts_with($rule, 'array:')) {
                return $this->keyedArrayType(explode(',', substr($rule, 6)));
            }

            $ruleName = explode(':', $rule)[0];

            if (isset(self::PRIMITIVE_RULES[$ruleName])) {
                return self::PRIMITIVE_RULES[$ruleName];
            }

            $format ??= self::FORMAT_RULES[$ruleName] ?? null;
        }

        return $format ?? 'unknown';
    }

    /**
     * Whether the rules declare the field an array (`array`, `array:keys`,
     * `list` or `Rule::array()`), wherever the rule sits in the list.
     *
     * @param  list<mixed>  $rules
     */
    protected function isArrayRule(array $rules): bool
    {
        return array_any(
            $this->variants($rules),
            static fn (array $variant): bool => array_any(
                $variant,
                static fn (mixed $rule): bool => is_string($rule) && in_array(explode(':', $rule)[0], ['array', 'list'], true),
            ),
        );
    }

    /**
     * Wrap a wildcard item type as an array type. Structured item types
     * (enum, in, anyOf) are rendered later, so they are wrapped as `arrayOf`.
     *
     * @return string|array{arrayOf: mixed}
     */
    protected function arrayOf(mixed $itemType): string|array
    {
        if (! is_string($itemType)) {
            return ['arrayOf' => $itemType];
        }

        return (str_contains($itemType, ' | ') ? '('.$itemType.')' : $itemType).'[]';
    }

    /**
     * Object type for `array:a,b` — only the listed keys are allowed, and
     * none of them is required by the rule itself.
     *
     * @param  list<string>  $keys
     */
    protected function keyedArrayType(array $keys): string
    {
        $members = [];

        foreach ($keys as $key) {
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $members[] = TypeScriptRenderer::propertyKey($key).'?: unknown';
        }

        if ($members === []) {
            return self::ARRAY_TYPE;
        }

        return '{ '.implode('; ', $members).' }';
    }

    /**
     * Check if the field is required: in every branch of its rules.
     *
     * @param  list<mixed>  $rules
     */
    protected function isRequired(array $rules): bool
    {
        return array_all($this->variants($rules), static function (array $variant): bool {
            // `sometimes` runs the other rules only when the key was sent.
            if (in_array('sometimes', $variant, true)) {
                return false;
            }

            // `present` allows an empty value, but the key itself must be sent;
            // `accepted` and `declined` require the key as `required` does.
            return array_any($variant, static fn (mixed $rule): bool => in_array($rule, ['required', 'present', 'accepted', 'declined'], true));
        });
    }

    /**
     * Check if the field is nullable: in any branch of its rules.
     *
     * @param  list<mixed>  $rules
     */
    protected function isNullable(array $rules): bool
    {
        return array_any($this->variants($rules), static fn (array $variant): bool => in_array('nullable', $variant, true));
    }

    /**
     * Check if a specific rule exists, in any branch of the rules.
     *
     * @param  list<mixed>  $rules
     */
    protected function hasRule(array $rules, string $ruleName): bool
    {
        foreach ($this->variants($rules) as $variant) {
            foreach ($variant as $rule) {
                if ($rule === $ruleName || (is_string($rule) && str_starts_with($rule, $ruleName.':'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Normalize a field's rules once, so the resolvers only see rule strings
     * (`|`-separated ones split) and the rule objects they read (`In`,
     * `Enum`, `AnyOf`), plus a `['when' => [rules, defaultRules]]` entry
     * for each `Rule::when()`.
     *
     * @return list<mixed>
     */
    protected function normalizeRules(mixed $rules): array
    {
        if ($rules === null) {
            return [];
        }

        if (is_string($rules)) {
            return explode('|', $rules);
        }

        $normalized = [];

        foreach (is_array($rules) ? $rules : [$rules] as $rule) {
            array_push($normalized, ...$this->normalizeRule($rule));
        }

        return $normalized;
    }

    /**
     * One rule of a list, normalized ({@see self::normalizeRules()}).
     *
     * @return list<mixed>
     */
    protected function normalizeRule(mixed $rule): array
    {
        // Rule::date(), Rule::numeric(), Rule::string(), Rule::array(), …
        foreach (self::STRINGABLE_RULES as $class) {
            if ($rule instanceof $class) {
                return explode('|', (string) $rule);
            }
        }

        // Rule::url(), Rule::uuid(), Rule::confirmed(), Rule::requiredIf…: [name, ...parameters]
        if (interface_exists(ParameterizedRule::class) && $rule instanceof ParameterizedRule) {
            return [$this->ruleString($rule->toArray())];
        }

        // The ['max', 10] form.
        if (is_array($rule) && array_is_list($rule) && is_string($rule[0] ?? null)) {
            return [$this->ruleString($rule)];
        }

        if ($rule instanceof ConditionalRules) {
            return [['when' => [$this->conditionalBranch($rule, true), $this->conditionalBranch($rule, false)]]];
        }

        if ($rule instanceof NestedRules) {
            $compiled = $this->compileNestedRules($rule, '');

            return is_array($compiled) && ! array_is_list($compiled) ? [] : $this->normalizeRules($compiled);
        }

        return match (true) {
            $rule instanceof File => ['file'],
            $rule instanceof Password => ['string'],
            $rule instanceof Email => ['email'],
            $rule instanceof Dimensions => ['dimensions'],
            default => [$rule],
        };
    }

    /**
     * A rule string from a rule name and its parameters.
     *
     * @param  list<mixed>  $parts
     */
    protected function ruleString(array $parts): string
    {
        $name = (string) array_shift($parts);
        $parameters = array_map(
            static fn (mixed $parameter): string => is_scalar($parameter) || $parameter instanceof UnitEnum
                ? (string) ($parameter instanceof UnitEnum ? enum_value($parameter) : $parameter)
                : '',
            $parts,
        );

        return $parameters === [] ? $name : $name.':'.implode(',', $parameters);
    }

    /**
     * The normalized rules of one branch of a `Rule::when()`.
     *
     * @return list<mixed>
     */
    protected function conditionalBranch(ConditionalRules $rules, bool $passes): array
    {
        try {
            return $this->normalizeRules($passes ? $rules->rules() : $rules->defaultRules());
        } catch (\Throwable $throwable) {
            $this->warnings[] = sprintf('a Rule::when() branch could not be read (%s); it was left out', $throwable->getMessage());

            return [];
        }
    }

    /**
     * The rules a `Rule::forEach()` callback returns for one item, called with
     * no item value; null (with a warning) when it cannot be called so.
     */
    protected function compileNestedRules(NestedRules $rules, string $attribute): mixed
    {
        try {
            $callback = new ReflectionProperty($rules, 'callback')->getValue($rules);

            return $callback(null, $attribute, [], []);
        } catch (\Throwable $throwable) {
            $this->warnings[] = sprintf(
                "the Rule::forEach() rules of '%s' could not be read (%s); its type is unknown",
                $attribute,
                $throwable->getMessage(),
            );

            return null;
        }
    }

    /**
     * The rule lists a field can end up with: its rules, or one list per
     * combination of `Rule::when()` branches.
     *
     * @param  list<mixed>  $rules  normalized
     * @return non-empty-list<list<mixed>>
     */
    protected function variants(array $rules): array
    {
        $variants = [[]];

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! array_key_exists('when', $rule)) {
                $variants = array_map(static fn (array $variant): array => [...$variant, $rule], $variants);

                continue;
            }

            $next = [];
            foreach ($rule['when'] as $branch) {
                foreach ($this->variants($branch) as $branchVariant) {
                    foreach ($variants as $variant) {
                        $next[] = [...$variant, ...$branchVariant];
                    }
                }
            }

            // Too many combinations to type: the condition is left out.
            if (count($next) <= self::MAX_VARIANTS) {
                $variants = $next;
            }
        }

        return $variants;
    }

    /**
     * Resolve the types of a Rule::anyOf() instance: each inner rule set is
     * resolved independently, then deduplicated.
     *
     * @return list<mixed>
     */
    protected function resolveAnyOfTypes(AnyOf $anyOf): array
    {
        $types = [];

        foreach ((array) new ReflectionProperty($anyOf, 'rules')->getValue($anyOf) as $ruleSet) {
            $type = $this->resolveType($this->normalizeRules($ruleSet));
            $types[serialize($type)] = $type;
        }

        return array_values($types);
    }

    /**
     * Create a FormRequest instance for rule extraction.
     *
     * We instantiate directly (bypassing the container's afterResolving hooks)
     * to avoid triggering validation before we even call rules(). We then set
     * the container manually so that rules() can use container->call() if needed.
     */
    protected function createRequestInstance(string $requestClass): FormRequest
    {
        /** @var FormRequest $instance */
        $instance = new $requestClass;
        $instance->setContainer(app());

        return $instance;
    }

    /**
     * Resolve the fully qualified class name from a PHP file.
     */
    /**
     * Stub route + user resolvers with a NullSafeProxy so rules() can
     * reference `$this->route(...)` / `$this->user(...)` without a loaded
     * route or auth context.
     */
    protected function applyNullSafeResolvers(FormRequest $formRequest): void
    {
        $formRequest->setRouteResolver(fn (): NullSafeProxy => new NullSafeProxy);
        $formRequest->setUserResolver(fn ($guard = null): NullSafeProxy => new NullSafeProxy);
    }

    /**
     * When both rules() calls fail, fall back to the class-level
     * #[TypefinderOverrides(...)] attribute as the full field set.
     * Returns null if the attribute is absent.
     *
     * @return ?list<array{name: string, type: string, required: bool, nullable: bool}>
     */
    protected function synthesizeFieldsFromOverrides(ReflectionClass $reflectionClass): ?array
    {
        $overrides = $this->getTypeOverrides($reflectionClass);
        if ($overrides === []) {
            return null;
        }

        $fields = [];
        foreach ($overrides as $name => $type) {
            $fields[] = [
                'name' => $name,
                'type' => $type,
                'required' => true,
                'nullable' => false,
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    protected function getTypeOverrides(ReflectionClass $reflectionClass): array
    {
        $attrs = $reflectionClass->getAttributes(TypefinderOverrides::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs === []) {
            return [];
        }

        return $attrs[0]->newInstance()->overrides;
    }
}
