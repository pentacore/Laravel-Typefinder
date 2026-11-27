<?php

declare(strict_types=1);

namespace Tests\Fixtures\Broadcasting;

use Pentacore\Typefinder\Attributes\TypefinderBroadcast;

/** The attribute still wins over a PHPDoc shape on broadcastWith() (#249). */
#[TypefinderBroadcast(payload: ['ids' => 'string[]'])]
class AttributeOverDocPayloadEvent extends DocumentedEvaluablePayloadEvent {}
