<?php

declare(strict_types=1);

namespace Tests\Unit\Analysis;

use Pentacore\Typefinder\Analysis\QueryRuleKeys;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Tests\Fixtures\Requests\Issue101QueryRequest;
use Tests\Fixtures\Requests\Issue129GrabRequest;
use Tests\Fixtures\Requests\Issue129ReleaseQueryRequest;

final class QueryRuleKeysTest extends TestCase
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

    public function test_finds_the_tagged_keys(): void
    {
        $this->assertSame(
            ['keys' => ['scope'], 'unreadable' => 0],
            QueryRuleKeys::of(new ReflectionClass(Issue101QueryRequest::class)),
        );
    }

    public function test_a_tagged_spread_contributes_its_keys(): void
    {
        $this->assertSame(
            ['keys' => ['service', 'item_id', 'season'], 'unreadable' => 0],
            QueryRuleKeys::of(new ReflectionClass(Issue129ReleaseQueryRequest::class)),
        );
    }

    public function test_an_untagged_spread_contributes_nothing(): void
    {
        $this->assertSame(
            ['keys' => [], 'unreadable' => 0],
            QueryRuleKeys::of(new ReflectionClass(Issue129GrabRequest::class)),
        );
    }

    public function test_a_tagged_spread_follows_array_merge(): void
    {
        $class = $this->declareRequest(<<<'PHP'
            public function rules(): array
            {
                return [/** @query */ ...$this->listing()];
            }

            private function listing(): array
            {
                return array_merge(['page' => ['integer']], $this->sorting());
            }

            private function sorting(): array
            {
                return ['sort' => ['string']];
            }
            PHP);

        $this->assertSame(['keys' => ['page', 'sort'], 'unreadable' => 0], QueryRuleKeys::of($class));
    }

    public function test_a_tagged_spread_skips_the_returns_of_a_closure(): void
    {
        $class = $this->declareRequest(<<<'PHP'
            public function rules(): array
            {
                return [/** @query */ ...$this->listing()];
            }

            private function listing(): array
            {
                $direction = static function (string $value): string {
                    if ($value === '') {
                        return 'asc';
                    }

                    return strtolower($value);
                };

                return ['direction' => ['in:'.$direction('ASC')]];
            }
            PHP);

        $this->assertSame(['keys' => ['direction'], 'unreadable' => 0], QueryRuleKeys::of($class));
    }

    public function test_a_tagged_spread_of_a_returned_variable_is_unreadable(): void
    {
        $class = $this->declareRequest(<<<'PHP'
            public function rules(): array
            {
                return ['title' => ['string'], /** @query */ ...$this->listing()];
            }

            private function listing(): array
            {
                $rules = [];
                $rules['page'] = ['integer'];

                return $rules;
            }
            PHP);

        $this->assertSame(['keys' => [], 'unreadable' => 1], QueryRuleKeys::of($class));
    }

    public function test_class_without_a_rules_method_has_no_tagged_keys(): void
    {
        $this->assertSame(
            ['keys' => [], 'unreadable' => 0],
            QueryRuleKeys::of(new ReflectionClass(stdClass::class)),
        );
    }

    public function test_rules_method_without_a_source_file_has_no_tagged_keys(): void
    {
        // Declared by eval(), so there is no file to read the tags from.
        $class = 'QueryRuleKeysEvalRequest'.bin2hex(random_bytes(4));
        eval(sprintf("final class %s { public function rules(): array { return [/** @query */ 'page' => ['integer']]; } }", $class));

        $this->assertSame(
            ['keys' => [], 'unreadable' => 0],
            QueryRuleKeys::of(new ReflectionClass($class)),
        );
    }

    /**
     * Declare a class with the given body in a temporary file, outside the
     * paths Rector rewrites, and load it.
     *
     * @return ReflectionClass<object>
     */
    private function declareRequest(string $body): ReflectionClass
    {
        $class = 'QueryRuleKeysSpreadRequest'.bin2hex(random_bytes(4));
        $file = sys_get_temp_dir().'/'.$class.'.php';
        file_put_contents($file, sprintf("<?php\n\nfinal class %s\n{\n%s\n}\n", $class, $body));
        $this->files[] = $file;

        require $file;

        return new ReflectionClass($class);
    }
}
