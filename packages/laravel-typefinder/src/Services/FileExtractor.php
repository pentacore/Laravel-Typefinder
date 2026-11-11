<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Services;

use Closure;
use Pentacore\Typefinder\Analysis\InertiaSharedProps;
use Pentacore\Typefinder\Analysis\KeyDriftDetector;
use Pentacore\Typefinder\Cache\ExtractionCache;
use Pentacore\Typefinder\Extractors\BroadcastExtractor;
use Pentacore\Typefinder\Extractors\ControllerExtractor;
use Pentacore\Typefinder\Extractors\EnumExtractor;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\ClassDiscovery;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Pentacore\Typefinder\TypefinderRegistry;
use Throwable;

/**
 * Extracts what one source file contributes to one category. Full and
 * incremental runs both go through here, so a file yields the same type (or
 * none, with the same warning) in either.
 *
 * Lives for one run. Resources and drift checks refer to the models and
 * enums of the run's cache, so those categories are extracted first.
 */
final class FileExtractor
{
    private ?EnumExtractor $enumExtractor = null;

    private ?ModelExtractor $modelExtractor = null;

    private ?RequestExtractor $requestExtractor = null;

    private ?ResourceExtractor $resourceExtractor = null;

    private ?ControllerExtractor $controllerExtractor = null;

    private ?BroadcastExtractor $broadcastExtractor = null;

    private ?KeyDriftDetector $keyDriftDetector = null;

    /** @var ?array<string, array<string, mixed>> */
    private ?array $modelsByClass = null;

    /** @var Closure(string): void */
    private readonly Closure $warn;

    /**
     * @param  callable(string): void  $warn
     */
    public function __construct(
        private readonly TypefinderRegistry $typefinderRegistry,
        private readonly ExtractionCache $extractionCache,
        callable $warn,
    ) {
        $this->warn = $warn(...);
    }

    /**
     * @return array<string, mixed>|null null when the file yields no type for the category
     *
     * @throws Throwable when an enum, model or controller cannot be extracted.
     *                   A request, resource or event that cannot is skipped with a warning.
     */
    public function extract(string $file, string $category): ?array
    {
        $class = ClassDiscovery::loadable($file, $this->skipped(...));
        if ($class === null) {
            return null;
        }

        return match ($category) {
            'enums' => $this->enum($class),
            'models' => $this->model($class),
            'requests' => $this->request($class),
            'resources' => $this->resource($class),
            'inertia' => $this->pages($class),
            'broadcasting' => $this->broadcast($class),
            default => null,
        };
    }

    /**
     * @param  class-string  $class
     */
    private function enum(string $class): ?array
    {
        $this->enumExtractor ??= new EnumExtractor;

        return $this->enumExtractor->isEmittable($class) ? $this->enumExtractor->extract($class) : null;
    }

    /**
     * @param  class-string  $class
     */
    private function model(string $class): ?array
    {
        $this->modelExtractor ??= new ModelExtractor(
            new ColumnTypeResolver,
            new CastTypeResolver((array) config('typefinder.casts.type_map', []), $this->typefinderRegistry),
            $this->warn,
        );

        return $this->modelExtractor->isEmittable($class) ? $this->modelExtractor->extract($class) : null;
    }

    /**
     * @param  class-string  $class
     */
    private function request(string $class): ?array
    {
        $this->requestExtractor ??= new RequestExtractor;
        if (! $this->requestExtractor->isEmittable($class)) {
            return null;
        }

        $request = $this->requestExtractor->tryExtract($class, $this->skipped(...));
        array_map($this->warn, $request['warnings'] ?? []);

        return $request;
    }

    /**
     * @param  class-string  $class
     */
    private function resource(string $class): ?array
    {
        $this->resourceExtractor ??= new ResourceExtractor(
            inferFromModelName: (bool) config('typefinder.resources.infer_from_model_name', true),
            modelClasses: $this->generatedClasses('models'),
            enumClasses: $this->generatedClasses('enums'),
            onWarn: $this->warn,
        );

        if (! $this->resourceExtractor->isEmittable($class)) {
            return null;
        }

        $resource = $this->resourceExtractor->tryExtract($class, $this->skipped(...));
        if ($resource === null) {
            return null;
        }

        $this->modelsByClass ??= array_column($this->extractionCache->entriesByCategory('models'), null, 'fqcn');
        $model = $this->modelsByClass[$resource['shape']['model'] ?? ''] ?? null;

        array_map($this->warn, $this->drift(fn (): array => $this->keyDriftDetector()->checkResource($resource, $model)));

        return $resource;
    }

    /**
     * The `#[TypefinderPage]` entries of a controller.
     *
     * @param  class-string  $class
     * @return array{pages: non-empty-list<array{component: string, props: array<string, string>, optional: list<string>, source: string}>}|null
     */
    private function pages(string $class): ?array
    {
        $this->controllerExtractor ??= new ControllerExtractor;

        $pages = $this->controllerExtractor->isEmittable($class) ? $this->controllerExtractor->extract($class) : [];
        if ($pages === []) {
            return null;
        }

        array_map($this->warn, $this->drift(fn (): array => $this->keyDriftDetector()->checkPages($pages)));

        return ['pages' => $pages];
    }

    /**
     * @param  class-string  $class
     */
    private function broadcast(string $class): ?array
    {
        $this->broadcastExtractor ??= new BroadcastExtractor;

        return $this->broadcastExtractor->isEmittable($class)
            ? $this->broadcastExtractor->tryExtract($class, $this->skipped(...))
            : null;
    }

    private function keyDriftDetector(): KeyDriftDetector
    {
        return $this->keyDriftDetector ??= new KeyDriftDetector(
            new PhpDocShapeResolver(new GeneratedClasses($this->generatedClasses('models'), $this->generatedClasses('enums'))),
            sharedPageProps: config('typefinder.inertia.enabled', false)
                // Props the app's Inertia middleware shares with every page.
                ? $this->drift(static fn (): array => InertiaSharedProps::fromDirectory(app_path('Http/Middleware')))
                : [],
        );
    }

    /**
     * Run a drift check. Drift only adds warnings, so a check that fails
     * (an autoloader throwing, unexpected source) yields none instead of
     * aborting generation.
     *
     * @param  Closure(): list<string>  $check
     * @return list<string>
     */
    private function drift(Closure $check): array
    {
        try {
            return $check();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The classes of a category that get a generated type.
     *
     * @return list<class-string>
     */
    private function generatedClasses(string $category): array
    {
        return array_values(array_column($this->extractionCache->entriesByCategory($category), 'fqcn'));
    }

    private function skipped(string $class, Throwable $throwable): void
    {
        ($this->warn)(sprintf('skipped %s: ', $class).$throwable->getMessage());
    }
}
