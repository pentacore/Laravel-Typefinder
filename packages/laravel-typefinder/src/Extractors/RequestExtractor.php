<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use BackedEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\AnyOf;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use Pentacore\Typefinder\Analysis\QueryRuleKeys;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Support\ClassDiscovery;
use Pentacore\Typefinder\Support\NullSafeProxy;
use ReflectionAttribute;
use ReflectionClass;

class RequestExtractor
{
    /**
     * Rule name → TypeScript type mapping.
     *
     * @var array<string, string>
     */
    protected const RULE_TYPE_MAP = [
        'string' => 'string',
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
        'integer' => 'number',
        'numeric' => 'number',
        'decimal' => 'number',
        'digits' => 'number',
        'max_digits' => 'number',
        'min_digits' => 'number',
        'boolean' => 'boolean',
        'accepted' => 'boolean',
        'accepted_if' => 'boolean',
        'declined' => 'boolean',
        'declined_if' => 'boolean',
        'file' => 'File',
        'image' => 'File',
        'array' => self::ARRAY_TYPE,
        'list' => self::LIST_TYPE,
    ];

    /**
     * An `array` rule accepts both lists and keyed arrays.
     */
    protected const ARRAY_TYPE = 'unknown[] | Record<string, unknown>';

    protected const LIST_TYPE = 'unknown[]';

    /**
     * Rules that make a field optional (conditional required).
     */
    protected const CONDITIONAL_RULES = [
        'required_if',
        'required_unless',
        'required_with',
        'required_with_all',
        'required_without',
        'required_without_all',
    ];

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
            $rules = $formRequest->rules();
        } catch (\Throwable) {
            $this->applyNullSafeResolvers($formRequest);
            try {
                $rules = $formRequest->rules();
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
     * Whether a class gets a request type of its own: a form request, not
     * `#[TypefinderIgnore]`d.
     *
     * @param  class-string  $requestClass
     */
    public function isEmittable(string $requestClass): bool
    {
        return is_subclass_of($requestClass, FormRequest::class)
            && new ReflectionClass($requestClass)->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) === [];
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

        foreach ($rules as $fieldName => $fieldRules) {
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
                '%d @query %s ignored: only a rule key written as a string literal can be tagged',
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
     * @param  list<mixed>  $rules
     */
    protected function resolveType(array $rules): mixed
    {
        foreach ($rules as $rule) {
            // Handle Rule::enum(SomeEnum::class)
            if ($rule instanceof Enum) {
                return $this->resolveEnumRule($rule);
            }

            // Handle Rule::in(['foo', 'bar'])
            if ($rule instanceof In) {
                $values = $this->extractInValues($rule);

                return ['in' => $values];
            }

            // Handle Rule::anyOf([...]) — Laravel 13+ only. Guard for older versions.
            if (class_exists(AnyOf::class) && $rule instanceof AnyOf) {
                $types = $this->resolveAnyOfTypes($rule);

                if ($types !== []) {
                    return count($types) === 1 ? $types[0] : ['anyOf' => $types];
                }
            }

            // Handle Rule::array() / Rule::array([...keys])
            if ($rule instanceof ArrayRule) {
                $rule = explode('|', (string) $rule)[0];
            }

            // Handle string rules
            if (is_string($rule)) {
                // Handle 'in:foo,bar,baz' string format
                if (str_starts_with($rule, 'in:')) {
                    $values = explode(',', substr($rule, 3));

                    return ['in' => $values];
                }

                // Handle 'array:name,email' — only the listed keys are allowed
                if (str_starts_with($rule, 'array:')) {
                    return $this->keyedArrayType(explode(',', substr($rule, 6)));
                }

                // Handle rules with parameters like 'digits:4'
                $ruleName = explode(':', $rule)[0];

                if (isset(self::RULE_TYPE_MAP[$ruleName])) {
                    return self::RULE_TYPE_MAP[$ruleName];
                }
            }
        }

        return 'unknown';
    }

    /**
     * Whether the rules declare the field an array (`array`, `array:keys`,
     * `list` or `Rule::array()`), wherever the rule sits in the list.
     *
     * @param  list<mixed>  $rules
     */
    protected function isArrayRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule instanceof ArrayRule) {
                return true;
            }

            if (is_string($rule) && in_array(explode(':', $rule)[0], ['array', 'list'], true)) {
                return true;
            }
        }

        return false;
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
     * Check if the field is required.
     *
     * @param  list<mixed>  $rules
     */
    protected function isRequired(array $rules): bool
    {
        // `sometimes` runs the other rules only when the key was sent.
        if (in_array('sometimes', $rules, true)) {
            return false;
        }

        foreach ($rules as $rule) {
            // `present` allows an empty value, but the key itself must be sent.
            if ($rule === 'required' || $rule === 'present') {
                return true;
            }

            // Conditional required rules make the field optional
            if (is_string($rule)) {
                $ruleName = explode(':', $rule)[0];
                if (in_array($ruleName, self::CONDITIONAL_RULES, true)) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Check if the field is nullable.
     *
     * @param  list<mixed>  $rules
     */
    protected function isNullable(array $rules): bool
    {
        return in_array('nullable', $rules, true);
    }

    /**
     * Check if a specific rule exists.
     *
     * @param  list<mixed>  $rules
     */
    protected function hasRule(array $rules, string $ruleName): bool
    {
        foreach ($rules as $rule) {
            if ($rule === $ruleName) {
                return true;
            }

            if (is_string($rule) && str_starts_with($rule, $ruleName.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize rules to an array format.
     *
     * @return list<mixed>
     */
    protected function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (is_array($rules)) {
            return $rules;
        }

        return [$rules];
    }

    /**
     * Resolve types from a Rule::anyOf() instance.
     *
     * Each inner rule set is resolved independently, then deduplicated.
     *
     * @return list<string>
     */
    protected function resolveAnyOfTypes(AnyOf $anyOf): array
    {
        $reflectionClass = new ReflectionClass($anyOf);
        $reflectionProperty = $reflectionClass->getProperty('rules');
        $ruleSets = $reflectionProperty->getValue($anyOf);

        $types = [];

        foreach ($ruleSets as $ruleSet) {
            $normalized = $this->normalizeRules($ruleSet);
            $type = $this->resolveType($normalized);

            if (is_string($type) && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Extract values from a Rule::in() instance.
     *
     * @return list<string>
     */
    protected function extractInValues(In $in): array
    {
        // The In rule's __toString method returns 'in:"val1","val2",...'
        $string = (string) $in;
        $valuesString = substr($string, 3); // Remove 'in:'

        return array_map(
            fn (string $v): string => trim($v, '"'),
            explode(',', $valuesString)
        );
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
