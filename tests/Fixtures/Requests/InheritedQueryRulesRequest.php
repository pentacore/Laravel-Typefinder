<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

/**
 * Inherits `rules()`, and its `@query` tags, from another file.
 */
final class InheritedQueryRulesRequest extends Issue101QueryRequest {}
