<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Protocol;

final class ProtocolCodec
{
    /** @param array<string, mixed> $message */
    public static function encode(array $message): string
    {
        return json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /** @return array<string, mixed>&array{type: string} */
    public static function decode(string $line): array
    {
        $trimmed = trim($line);
        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new ProtocolException('malformed JSON: '.$jsonException->getMessage(), 0, $jsonException);
        }

        if (! is_array($decoded)) {
            throw new ProtocolException('expected a JSON object');
        }

        if (! isset($decoded['type']) || ! is_string($decoded['type'])) {
            throw new ProtocolException('message missing "type" field (or not a string)');
        }

        return $decoded;
    }
}
