<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

final class ClientOperationHasher
{
    /**
     * Build a stable fingerprint for the business payload associated with a
     * client-generated operation identifier.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            self::normalize($payload),
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            $digest = hash_file('sha256', $value->getPathname());
            if ($digest === false) {
                throw new RuntimeException('Could not fingerprint the uploaded file.');
            }

            return [
                'sha256' => $digest,
                'size' => $value->getSize(),
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
