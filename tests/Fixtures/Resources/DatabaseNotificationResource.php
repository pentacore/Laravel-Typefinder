<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shares its short name with Laravel's own DatabaseNotification model, which
 * is not one of the app's models. The name convention must not match it.
 */
class DatabaseNotificationResource extends JsonResource {}
