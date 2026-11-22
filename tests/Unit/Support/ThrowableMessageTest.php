<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Pentacore\Typefinder\Support\ThrowableMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ThrowableMessageTest extends TestCase
{
    public function test_keeps_a_non_empty_message(): void
    {
        $this->assertSame('boom', ThrowableMessage::describe(new RuntimeException('boom')));
    }

    public function test_names_the_exception_when_the_message_is_empty(): void
    {
        $this->assertSame(
            NotFoundHttpException::class.' (no message)',
            ThrowableMessage::describe(new NotFoundHttpException),
        );
    }

    public function test_treats_a_whitespace_message_as_empty(): void
    {
        $this->assertSame(RuntimeException::class.' (no message)', ThrowableMessage::describe(new RuntimeException('  ')));
    }
}
