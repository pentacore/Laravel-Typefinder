<?php

namespace Pentacore\Typefinder\Extractors;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\AnyOf;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\In;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

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
        'array' => 'array',
        'list' => 'array',
    ];

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
     * Extract type information from a single FormRequest class.
     *
     * @param  class-string<FormRequest>  $requestClass
     * @return array{name: string, fqcn: class-string, fields: list<array>}
     */
    public function extract(string $requestClass): array
    {
        $reflection = new ReflectionClass($requestClass);
        $instance = $this->createRequestInstance($requestClass);
        $rules = $instance->rules();

        $fields = $this->parseRules($rules);

        return [
            'name' => $reflection->getShortName(),
            'fqcn' => $requestClass,
            'fields' => $fields,
        ];
    }

    /**
     * Discover and extract all FormRequests from a directory.
     *
     * @return list<array{name: string, fqcn: class-string, fields: list<array>}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $results = [];
        $finder = Finder::create()->files()->name('*.php')->in($path);

        foreach ($finder as $file) {
            $className = $this->resolveClassName($file->getRealPath());

            if ($className === null || ! class_exists($className)) {
                continue;
            }

            if (! is_subclass_of($className, FormRequest::class)) {
                continue;
            }

            if ($onExtract !== null) {
                $onExtract($className);
            }

            $results[] = $this->extract($className);
        }

        return $results;
    }

    /**
     * Parse all rules into a structured fields array.
     *
     * @param  array<string, mixed>  $rules
     * @return list<array>
     */
    protected function parseRules(array $rules): array
    {
        $topLevel = [];
        $nested = [];
        $wildcardTypes = [];
        $confirmedFields = [];

        // Separate top-level, nested, and wildcard rules
        foreach ($rules as $fieldName => $fieldRules) {
            $fieldRules = $this->normalizeRules($fieldRules);

            if (str_contains($fieldName, '.')) {
                $parts = explode('.', $fieldName, 2);

                if ($parts[1] === '*') {
                    // Wildcard rule: tags.* => 'string' → tags is string[]
                    $wildcardTypes[$parts[0]] = $this->resolveType($fieldRules);
                } else {
                    // Nested rule: metadata.key => 'string'
                    $nested[$parts[0]][] = [
                        'path' => $parts[1],
                        'rules' => $fieldRules,
                    ];
                }
            } else {
                $topLevel[$fieldName] = $fieldRules;
            }
        }

        $fields = [];

        foreach ($topLevel as $fieldName => $fieldRules) {
            $required = $this->isRequired($fieldRules);
            $nullable = $this->isNullable($fieldRules);
            $type = $this->resolveType($fieldRules);

            // Check for 'confirmed' rule
            if ($this->hasRule($fieldRules, 'confirmed')) {
                $confirmedFields[$fieldName.'_confirmation'] = $type;
            }

            // Handle array/list types with wildcard items
            if ($type === 'array' && isset($wildcardTypes[$fieldName])) {
                $itemType = $wildcardTypes[$fieldName];
                $type = $itemType.'[]';
            } elseif ($type === 'array' && isset($nested[$fieldName])) {
                // Nested object
                $children = $this->parseNestedFields($nested[$fieldName]);
                $fields[] = [
                    'name' => $fieldName,
                    'type' => 'object',
                    'required' => $required,
                    'nullable' => $nullable,
                    'children' => $children,
                ];

                continue;
            }

            $fields[] = [
                'name' => $fieldName,
                'type' => $type,
                'required' => $required,
                'nullable' => $nullable,
            ];
        }

        // Add auto-generated confirmation fields
        foreach ($confirmedFields as $confirmFieldName => $confirmType) {
            // Only add if not explicitly defined
            if (! isset($topLevel[$confirmFieldName])) {
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
     * Parse nested field rules into children array.
     *
     * @param  list<array{path: string, rules: list<mixed>}>  $nestedRules
     * @return list<array>
     */
    protected function parseNestedFields(array $nestedRules): array
    {
        $children = [];

        foreach ($nestedRules as $nested) {
            $rules = $nested['rules'];
            $children[] = [
                'name' => $nested['path'],
                'type' => $this->resolveType($rules),
                'required' => $this->isRequired($rules),
                'nullable' => $this->isNullable($rules),
            ];
        }

        return $children;
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
                $reflection = new ReflectionClass($rule);
                $typeProperty = $reflection->getProperty('type');
                $enumClass = $typeProperty->getValue($rule);

                return ['enum' => $enumClass];
            }

            // Handle Rule::in(['foo', 'bar'])
            if ($rule instanceof In) {
                $values = $this->extractInValues($rule);

                return ['in' => $values];
            }

            // Handle Rule::anyOf([...]) — Laravel 13+ only. Guard for older versions.
            if (class_exists(AnyOf::class) && $rule instanceof AnyOf) {
                $types = $this->resolveAnyOfTypes($rule);

                if (! empty($types)) {
                    return count($types) === 1 ? $types[0] : ['anyOf' => $types];
                }
            }

            // Handle string rules
            if (is_string($rule)) {
                // Handle 'in:foo,bar,baz' string format
                if (str_starts_with($rule, 'in:')) {
                    $values = explode(',', substr($rule, 3));

                    return ['in' => $values];
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
     * Check if the field is required.
     *
     * @param  list<mixed>  $rules
     */
    protected function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule === 'required') {
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
    protected function resolveAnyOfTypes(AnyOf $rule): array
    {
        $reflection = new ReflectionClass($rule);
        $rulesProperty = $reflection->getProperty('rules');
        $ruleSets = $rulesProperty->getValue($rule);

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
    protected function extractInValues(In $rule): array
    {
        // The In rule's __toString method returns 'in:"val1","val2",...'
        $string = (string) $rule;
        $valuesString = substr($string, 3); // Remove 'in:'

        return array_map(
            fn (string $v) => trim($v, '"'),
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
    protected function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if (! preg_match('/namespace\s+(.+?);/', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1].'\\'.$classMatch[1];
    }
}
