<?php

namespace Tests\Unit\Support;

use App\Enums\PostStatus;
use App\Enums\PostTag;
use App\Models\User;
use Pentacore\Typefinder\Support\ParsedSource;
use PHPUnit\Framework\TestCase;

final class ParsedSourceTest extends TestCase
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

    private function write(string $source, ?string $path = null): string
    {
        $path ??= tempnam(sys_get_temp_dir(), 'parsedsource');
        $this->files[] = $path;
        file_put_contents($path, $source);

        return $path;
    }

    public function test_resolves_imported_aliased_group_and_relative_names(): void
    {
        $path = $this->write(<<<'PHP'
        <?php
        namespace App\Http\Resources;

        use App\Models\User;
        use App\Enums\{PostStatus, PostTag as Tag};
        use Foo\Bar as Baz;

        class X {}
        PHP);

        $source = ParsedSource::forFile($path);
        $this->assertNotNull($source);
        $fqcn = 'App\Http\Resources\X';

        $this->assertSame(User::class, $source->resolveName($fqcn, 'User'));
        $this->assertSame(PostTag::class, $source->resolveName($fqcn, 'Tag'));
        $this->assertSame(PostStatus::class, $source->resolveName($fqcn, 'PostStatus'));
        $this->assertSame('Foo\Bar', $source->resolveName($fqcn, 'Baz'));
        $this->assertSame('App\Http\Resources\Sub\Thing', $source->resolveName($fqcn, 'Sub\Thing'));
        $this->assertSame('Vendor\Pkg\Thing', $source->resolveName($fqcn, '\Vendor\Pkg\Thing'));
        $this->assertSame('App\Http\Resources\Unimported', $source->resolveName($fqcn, 'Unimported'));
    }

    public function test_trait_use_and_function_imports_are_not_class_imports(): void
    {
        $path = $this->write(<<<'PHP'
        <?php
        namespace NS;

        use function strlen as User;

        class X {
            use SomeTrait;
        }
        PHP);

        $source = ParsedSource::forFile($path);
        $this->assertNotNull($source);

        $this->assertSame('NS\User', $source->resolveName('NS\X', 'User'));
        $this->assertSame('NS\SomeTrait', $source->resolveName('NS\X', 'SomeTrait'));
    }

    public function test_method_doc_found_when_attribute_sits_between_doc_and_method(): void
    {
        $path = $this->write(<<<'PHP'
        <?php
        namespace NS;

        class X {
            /** @return array{a: int} */
            #[\Override]
            public function toArray($r): array { return []; }
        }
        PHP);

        $doc = ParsedSource::forFile($path)?->methodDoc('NS\X', 'TOARRAY');

        $this->assertNotNull($doc);
        $this->assertStringContainsString('array{a: int}', $doc);
    }

    public function test_method_doc_is_scoped_to_its_class(): void
    {
        $path = $this->write(<<<'PHP'
        <?php
        namespace NS;

        class A {
            /** @return array{a: int} */
            public function toArray($r): array { return []; }
        }
        class B {
            public function toArray($r): array { return []; }
        }
        PHP);

        $source = ParsedSource::forFile($path);
        $this->assertNotNull($source);

        $this->assertNotNull($source->methodDoc('NS\A', 'toArray'));
        $this->assertNull($source->methodDoc('NS\B', 'toArray'));
        $this->assertNotNull($source->methodNode('NS\A', 'toArray'));
        $this->assertNull($source->methodNode('NS\B', 'missing'));
    }

    public function test_class_doc_returned(): void
    {
        $path = $this->write(<<<'PHP'
        <?php
        namespace NS;

        /** The class doc. */
        class X {}
        PHP);

        $doc = ParsedSource::forFile($path)?->classDoc('NS\X');

        $this->assertNotNull($doc);
        $this->assertStringContainsString('The class doc.', $doc);
    }

    public function test_reparses_after_content_change(): void
    {
        $path = $this->write("<?php\nnamespace NS;\n/** first */\nclass X {}\n");
        $this->assertStringContainsString('first', (string) ParsedSource::forFile($path)?->classDoc('NS\X'));

        $this->write("<?php\nnamespace NS;\n/** second */\nclass X {}\n", $path);
        $this->assertStringContainsString('second', (string) ParsedSource::forFile($path)?->classDoc('NS\X'));
    }

    public function test_returns_null_for_unparsable_file(): void
    {
        $path = $this->write('<?php class {');

        $this->assertNull(ParsedSource::forFile($path));
        $this->assertNull(ParsedSource::forFile($path.'.missing'));
    }
}
