<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector;

use Pentacore\Typefinder\Rector\Support\DocClassNames;
use Pentacore\Typefinder\Rector\Support\DocTokens;
use Pentacore\Typefinder\Rector\Support\FileImports;
use Pentacore\Typefinder\Resolvers\PhpDocReader;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Writes the imported class names in a `@typefinder-return` type fully
 * qualified. Rector reads class names only in the tags phpdoc-parser knows,
 * so with `removeUnusedImports` it drops an import used only in this tag and
 * the name no longer resolves. Rules run before that clean-up, so the
 * qualified name is in place when the import goes.
 *
 * @see https://github.com/pentacore/Laravel-Typefinder/issues/239
 */
final class QualifyTypefinderReturnRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Write class names in @typefinder-return fully qualified, so removing unused imports keeps them resolvable', [
            new CodeSample(
                <<<'CODE_SAMPLE'
                    use App\Enums\Role;

                    /** @typefinder-return array{can: array<value-of<Role>, bool>} */
                    public function share(Request $request): array
                    CODE_SAMPLE,
                <<<'CODE_SAMPLE'
                    use App\Enums\Role;

                    /** @typefinder-return array{can: array<value-of<\App\Enums\Role>, bool>} */
                    public function share(Request $request): array
                    CODE_SAMPLE,
            ),
        ]);
    }

    /** @return array<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class, Function_::class];
    }

    /** @param  ClassMethod|Function_  $node */
    public function refactor(Node $node): ?Node
    {
        $doc = $node->getDocComment();

        if (! $doc instanceof Doc || stripos($doc->getText(), PhpDocReader::TYPEFINDER_RETURN_TAG) === false) {
            return null;
        }

        $tokens = DocTokens::of($doc->getText());
        $imports = FileImports::of($this->getFile()->getNewStmts());
        $edits = [];

        foreach ($tokens->typeTags([PhpDocReader::TYPEFINDER_RETURN_TAG]) as ['type' => $type]) {
            foreach (DocClassNames::in($type) as ['name' => $name, 'index' => $index]) {
                $qualified = $imports->resolve($name);

                if ($qualified !== null && $index >= 0 && $tokens->token($index)[0] === $name) {
                    $edits[] = ['start' => $index, 'end' => $index, 'text' => '\\'.$qualified];
                }
            }
        }

        if ($edits === []) {
            return null;
        }

        $node->setDocComment(new Doc($tokens->apply($edits), $doc->getStartLine(), $doc->getStartFilePos(), $doc->getStartTokenPos()));
        // Rector caches the parsed doc on the node; drop it so no later rule prints the old text.
        $node->setAttribute(AttributeKey::PHP_DOC_INFO, null);

        return $node;
    }
}
