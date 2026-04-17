<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Protocol\ProtocolCodec;
use Pentacore\Typefinder\Protocol\ProtocolException;
use Tests\TestCase;

final class ProtocolCodecTest extends TestCase
{
    public function test_encode_produces_single_line_with_trailing_newline(): void
    {
        $line = ProtocolCodec::encode(['type' => 'ready', 'protocol' => 1]);

        $this->assertStringEndsWith("\n", $line);
        $this->assertSame(1, substr_count($line, "\n"));
    }

    public function test_decode_accepts_valid_regen_request(): void
    {
        $msg = ProtocolCodec::decode('{"type":"regen","id":"x1","paths":["/a","/b"]}');

        $this->assertSame('regen', $msg['type']);
        $this->assertSame('x1', $msg['id']);
        $this->assertSame(['/a', '/b'], $msg['paths']);
    }

    public function test_decode_throws_on_malformed_json(): void
    {
        $this->expectException(ProtocolException::class);
        ProtocolCodec::decode('{not json');
    }

    public function test_decode_throws_when_type_is_missing(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessageMatches('/missing "type"/');
        ProtocolCodec::decode('{"id":"x"}');
    }

    public function test_decode_throws_when_type_is_not_a_string(): void
    {
        $this->expectException(ProtocolException::class);
        ProtocolCodec::decode('{"type":123}');
    }
}
