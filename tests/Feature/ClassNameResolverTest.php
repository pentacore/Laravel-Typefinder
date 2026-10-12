<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Pentacore\Typefinder\Support\ClassNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ClassNameResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function sources(): iterable
    {
        yield 'enum' => ["<?php\nnamespace App\\Enums;\nenum Ability: string {}", 'App\\Enums\\Ability'];

        yield 'class' => ["<?php\nnamespace App\\Models;\nclass User {}", User::class];

        yield 'final readonly class' => ["<?php\nnamespace App;\nfinal readonly class Thing {}", 'App\\Thing'];

        yield 'interface' => ["<?php\nnamespace App;\ninterface Contract {}", 'App\\Contract'];

        yield 'single-segment namespace' => ["<?php\nnamespace App;\nclass Foo {}", 'App\\Foo'];

        yield 'braced namespace' => ["<?php\nnamespace App\\Models {\n    class Post {}\n}", Post::class];

        yield 'global namespace' => ["<?php\nclass Legacy {}", 'Legacy'];

        yield 'no declaration' => ["<?php\nnamespace App;\nfunction helper() {}", null];

        yield '"enum <word>" in a docblock' => [
            "<?php\nnamespace App\\Enums;\n/**\n * Mirrored to TypeScript as a\n * backed enum as well as kept in sync.\n */\nenum Ability: string {}",
            'App\\Enums\\Ability',
        ];

        yield '"class <word>" in a line comment' => [
            "<?php\nnamespace App\\Models;\n// This class handles users.\nclass User {}",
            User::class,
        ];

        yield '"namespace X;" in a comment before the declaration' => [
            "<?php\n// see namespace Wrong\\Place;\nnamespace App\\Enums;\nenum Ability: string {}",
            'App\\Enums\\Ability',
        ];

        yield '"class <word>" in a string' => [
            "<?php\nnamespace App;\n\$x = 'class Nope';\nclass Real {}",
            'App\\Real',
        ];

        yield '::class constant before the declaration' => [
            "<?php\nnamespace App;\nuse Foo\\Bar;\n\$x = Bar::class;\nclass Real {}",
            'App\\Real',
        ];

        yield 'anonymous class before the declaration' => [
            "<?php\nnamespace App;\n\$x = new class {};\nclass Real {}",
            'App\\Real',
        ];

        yield 'attribute before the declaration' => [
            "<?php\nnamespace App;\n#[Attr(Other::class)]\nfinal class Real {}",
            'App\\Real',
        ];
    }

    #[DataProvider('sources')]
    public function test_resolves_class_name_from_tokens(string $source, ?string $expected): void
    {
        $this->assertSame($expected, ClassNameResolver::fromSource($source));
    }

    public function test_returns_null_for_unreadable_file(): void
    {
        $this->assertNull(ClassNameResolver::fromFile('/nonexistent/Nope.php'));
    }
}
