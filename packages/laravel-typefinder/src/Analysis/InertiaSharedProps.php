<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Pentacore\Typefinder\Resolvers\PhpDocScope;
use Pentacore\Typefinder\Resolvers\PhpDocTypeMapper;
use Pentacore\Typefinder\Support\ClassNameResolver;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Symfony\Component\Finder\Finder;

/**
 * The prop keys an app shares with every Inertia page: the literal keys any
 * return of `share()` produces in classes extending `Inertia\Middleware`
 * (`array_merge(parent::share($request), [...])`, `[...parent::share($request),
 * ...]`). Source is only parsed, never loaded, so Inertia need not be
 * installed. Unreadable or unparsable files contribute nothing.
 */
final class InertiaSharedProps
{
    private const string MIDDLEWARE = 'Inertia\\Middleware';

    /** In precedence order (PHPStan's own). */
    private const array RETURN_TAGS = ['@phpstan-return', '@psalm-return', '@return'];

    /** Inertia's own middleware shares the validation errors, one message per field. */
    private const array ERRORS = ['name' => 'errors', 'type' => ['kind' => 'ts', 'ts' => 'Record<string, string>'], 'optional' => false];

    private const array UNKNOWN = ['kind' => 'ts', 'ts' => 'unknown'];

    /**
     * @return list<string> keys in order of first appearance
     */
    public static function fromDirectory(string $directory): array
    {
        $keys = [];

        foreach (self::middleware($directory) as ['returns' => $returns]) {
            foreach ($returns as $returned) {
                foreach ($returned->keys() as ['key' => $key]) {
                    $keys[$key] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($keys));
    }

    /**
     * The shared props as an `object` type node (the {@see PhpDocTypeMapper}
     * format): `errors`, then every key `share()` returns. A key is typed by a
     * PHPDoc `@return array{…}` on `share()` when it has one, else `unknown`;
     * it is optional when a return lacks it or only adds it conditionally.
     * Keys the doc declares that the code does not show (from
     * `parent::share()`) are included too.
     *
     * @param  null|callable(string): void  $onWarn  told about doc types that map to `unknown`
     * @return array{kind: 'object', fields: list<array{name: string, type: array<string, mixed>, optional: bool}>, open: false}
     */
    public static function shape(string $directory, ?callable $onWarn = null, ?GeneratedClasses $generatedClasses = null): array
    {
        $mapper = new PhpDocTypeMapper($generatedClasses ?? new GeneratedClasses, $onWarn);
        /** @var array<string, array{name: string, type: array<string, mixed>, optional: bool}> $fields */
        $fields = ['errors' => self::ERRORS];

        foreach (self::middleware($directory) as ['class' => $class, 'source' => $source, 'method' => $method, 'returns' => $returns]) {
            $documented = self::documented($class, $source, $method, $mapper);

            foreach (self::codeKeys($returns) as $key => $optional) {
                $fields[$key] = ['name' => $key, 'type' => $documented[$key]['type'] ?? ($fields[$key]['type'] ?? self::UNKNOWN), 'optional' => $optional];
            }

            foreach ($documented as $key => $field) {
                $fields[$key] ??= $field;
            }
        }

        return ['kind' => 'object', 'fields' => array_values($fields), 'open' => false];
    }

    /**
     * Each key any return produces, with whether it may be absent.
     *
     * @param  list<ReturnedArray>  $returns
     * @return array<string, bool>
     */
    private static function codeKeys(array $returns): array
    {
        $optional = [];

        foreach ($returns as $returned) {
            foreach ($returned->keys() as ['key' => $key, 'conditional' => $conditional]) {
                $optional[$key] = ($optional[$key] ?? false) || $conditional;
            }
        }

        foreach (array_keys($optional) as $key) {
            $everywhere = array_all($returns, static fn (ReturnedArray $returned): bool => in_array($key, array_column($returned->keys(), 'key'), true));
            $optional[$key] = $optional[$key] || ! $everywhere;
        }

        return $optional;
    }

    /**
     * The fields of a `@return array{…}` on `share()`, by name.
     *
     * @return array<string, array{name: string, type: array<string, mixed>, optional: bool}>
     */
    private static function documented(string $class, ParsedSource $source, ClassMethod $method, PhpDocTypeMapper $mapper): array
    {
        $doc = $method->getDocComment()?->getText();

        if ($doc === null) {
            return [];
        }

        $config = new ParserConfig([]);
        $constExprParser = new ConstExprParser($config);
        $parser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);

        // A doc comment always opens with `/**`, and a bad tag parses as an invalid tag value.
        $type = self::returnType($parser->parse(new TokenIterator(new Lexer($config)->tokenize($doc))));

        if (! $type instanceof ArrayShapeNode || PhpDocTypeMapper::isListShape($type)) {
            return [];
        }

        $scope = new PhpDocScope($class, $class, static fn (string $name): string => $source->resolveName($class, $name));
        $mapped = $mapper->map($type, $scope, '', 'share');
        $fields = [];

        foreach ($mapped['fields'] ?? [] as $field) {
            $fields[(string) $field['name']] = $field;
        }

        return $fields;
    }

    private static function returnType(PhpDocNode $doc): mixed
    {
        foreach (self::RETURN_TAGS as $tag) {
            $values = $doc->getReturnTagValues($tag);

            if ($values !== []) {
                return $values[0]->type;
            }
        }

        return null;
    }

    /**
     * The `share()` of every `Inertia\Middleware` subclass in $directory.
     *
     * @return list<array{class: string, source: ParsedSource, method: ClassMethod, returns: list<ReturnedArray>}>
     */
    private static function middleware(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $reader = new ReturnedArrayReader;
        $found = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php')->sortByName() as $file) {
            $path = $file->getRealPath() ?: $file->getPathname();
            $class = ClassNameResolver::fromFile($path);
            $source = $class === null ? null : ParsedSource::forFile($path);

            if ($class === null || ! $source instanceof ParsedSource || strcasecmp((string) $source->parentName($class), self::MIDDLEWARE) !== 0) {
                continue;
            }

            $method = $source->methodNode($class, 'share');

            if ($method instanceof ClassMethod) {
                $found[] = ['class' => $class, 'source' => $source, 'method' => $method, 'returns' => $reader->returns($method)];
            }
        }

        return $found;
    }
}
