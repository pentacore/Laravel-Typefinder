<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector\Support;

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use Rector\PhpParser\Node\FileNode;

/**
 * The class imports (`use X;`, `use X as Y;`, `use A\{B, C};`) of a file,
 * by lower-case alias.
 */
final readonly class FileImports
{
    /** @param  array<string, string>  $byAlias  lower-case alias => fully qualified name, no leading `\` */
    private function __construct(private array $byAlias) {}

    /**
     * @param  array<Node>  $stmts  the file's top-level statements (Rector
     *                              wraps them in a `FileNode`, which is read through)
     */
    public static function of(array $stmts): self
    {
        $byAlias = [];

        foreach (self::topLevel($stmts) as $use) {
            if ($use instanceof Use_ && $use->type === Use_::TYPE_NORMAL) {
                foreach ($use->uses as $item) {
                    $byAlias[strtolower($item->getAlias()->toString())] = $item->name->toString();
                }
            }

            if ($use instanceof GroupUse) {
                // A group sets its kind per item (`use A\{B, function c}`), or once for all.
                foreach ($use->uses as $item) {
                    if (($use->type === Use_::TYPE_UNKNOWN ? $item->type : $use->type) === Use_::TYPE_NORMAL) {
                        $byAlias[strtolower($item->getAlias()->toString())] = $use->prefix->toString().'\\'.$item->name->toString();
                    }
                }
            }
        }

        return new self($byAlias);
    }

    /**
     * @param  array<Node>  $stmts
     * @return list<Node>
     */
    private static function topLevel(array $stmts): array
    {
        $found = [];

        foreach ($stmts as $stmt) {
            $found = $stmt instanceof Namespace_ || $stmt instanceof FileNode
                ? [...$found, ...self::topLevel($stmt->stmts)]
                : [...$found, $stmt];
        }

        return $found;
    }

    /**
     * The fully qualified name a name written in a doc resolves to through
     * an import (`Foo` or `Foo\Bar` where `Foo` is imported), or null when
     * its first segment is not imported or the name is already qualified.
     */
    public function resolve(string $name): ?string
    {
        if ($name === '' || $name[0] === '\\') {
            return null;
        }

        $segments = explode('\\', $name, 2);
        $imported = $this->byAlias[strtolower($segments[0])] ?? null;

        if ($imported === null) {
            return null;
        }

        return isset($segments[1]) ? $imported.'\\'.$segments[1] : $imported;
    }
}
