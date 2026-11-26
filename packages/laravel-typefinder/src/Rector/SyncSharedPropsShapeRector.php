<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector;

use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use Pentacore\Typefinder\Rector\Support\ShapeDocSync;
use Pentacore\Typefinder\Rector\Support\ValueTypeGuesser;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Documents the props an Inertia middleware's `share()` returns, which
 * `SharedPageProps` types as `unknown` until a doc declares them. With no
 * shape on `share()` it adds a `@typefinder-return array{…}` of every
 * literal key (a Typefinder-only tag, so PHPStan does not check it against
 * `parent::share()`); with one it adds the keys the shape lacks and makes
 * conditional ones optional. Keys from `parent::share()` are never removed.
 * Types are guessed where clear and `mixed` otherwise, for you to fill in.
 */
final class SyncSharedPropsShapeRector extends AbstractRector
{
    private const string MIDDLEWARE = 'Inertia\\Middleware';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Document the shared props returned by the share() method of an Inertia middleware', [
            new CodeSample(
                <<<'CODE_SAMPLE'
                    public function share(Request $request): array
                    {
                        return [...parent::share($request), 'locale' => 'en', 'user' => fn () => $request->user()];
                    }
                    CODE_SAMPLE,
                <<<'CODE_SAMPLE'
                    /**
                     * @typefinder-return array{
                     *     locale: string,
                     *     user: mixed,
                     * }
                     */
                    public function share(Request $request): array
                    {
                        return [...parent::share($request), 'locale' => 'en', 'user' => fn () => $request->user()];
                    }
                    CODE_SAMPLE,
            ),
        ]);
    }

    /** @return array<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /** @param  Class_  $node */
    public function refactor(Node $node): ?Node
    {
        if (! $node->extends instanceof Name || ! ReturnedArrayReader::refersTo($node->extends, self::MIDDLEWARE)) {
            return null;
        }

        $method = $node->getMethod('share');

        if (! $method instanceof ClassMethod) {
            return null;
        }

        $returns = new ReturnedArrayReader()->returns($method);
        $sync = new ShapeDocSync(new ValueTypeGuesser($this->getType(...)));
        $doc = $method->getDocComment();
        $synced = $doc instanceof Doc && $this->hasShape($doc->getText())
            ? $sync->sync($doc->getText(), $returns)
            : $this->withNewShape($doc?->getText(), $sync, $returns);

        if ($synced === null) {
            return null;
        }

        $method->setDocComment(new Doc($synced, $doc?->getStartLine() ?? -1, $doc?->getStartFilePos() ?? -1, $doc?->getStartTokenPos() ?? -1));
        $method->setAttribute(AttributeKey::PHP_DOC_INFO, null);

        return $node;
    }

    private function hasShape(string $doc): bool
    {
        return preg_match('/@(?:typefinder-|phpstan-|psalm-)?return\s+array\s*\{/i', $doc) === 1;
    }

    /**
     * $doc with a new shape tag before its end, or a new doc of just the tag.
     *
     * @param  list<ReturnedArray>  $returns
     */
    private function withNewShape(?string $doc, ShapeDocSync $sync, array $returns): ?string
    {
        if ($doc === null) {
            $tag = $sync->shapeTag($returns, ' ');

            return $tag === null ? null : "/**\n * ".$tag."\n */";
        }

        if (preg_match('/\n([ \t]*)\*\/\s*$/', $doc, $match) !== 1) {
            // A one-line doc: rewrite it as a block.
            $tag = $sync->shapeTag($returns, ' ');
            $text = trim((string) preg_replace('#^/\*\*|\*/$#', '', trim($doc)));

            return $tag === null ? null : "/**\n * ".($text === '' ? '' : $text."\n *\n * ").$tag."\n */";
        }

        $indent = $match[1];
        $tag = $sync->shapeTag($returns, $indent.' ');

        if ($tag === null) {
            return null;
        }

        $end = strrpos($doc, '*/');
        $body = rtrim(substr($doc, 0, (int) $end - strlen($indent)));
        $lastLine = substr($body, (int) strrpos($body, "\n") + 1);
        $blank = trim($lastLine) === '*' || trim($lastLine) === '/**' ? '' : "\n".$indent.' *';

        return $body.$blank."\n".$indent.' * '.$tag."\n".$indent.' */';
    }
}
