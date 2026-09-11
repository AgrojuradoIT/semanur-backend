<?php

namespace App\Services\Inventory\Orders;

use Throwable;

/**
 * Minimal, dependency-free reader of the EXIF orientation tag (0x0112) inside
 * the first JPEG APP1 "Exif" segment. It exists because the hosting PHP does
 * not load the exif extension; the service prefers exif_read_data when it is
 * available.
 *
 * The parser is deliberately conservative: malformed, ambiguous or
 * out-of-range structures return 1 (no rotation). A wrong guess here would
 * rotate or flip evidence, so falling back to the original orientation is the
 * only safe default. Bounds are checked before every unpack and the whole
 * parse is wrapped in a Throwable guard by read().
 */
final class JpegExifOrientation
{
    public const DEFAULT_ORIENTATION = 1;

    private const MARKER_APP1 = 0xE1;

    private const MARKER_SOS = 0xDA;

    private const TAG_ORIENTATION = 0x0112;

    private const MAX_IFD_ENTRIES = 128;

    public static function read(string $bytes): int
    {
        try {
            return self::parse($bytes);
        } catch (Throwable) {
            return self::DEFAULT_ORIENTATION;
        }
    }

    private static function parse(string $bytes): int
    {
        $length = strlen($bytes);

        if ($length < 4 || $bytes[0] !== "\xFF" || $bytes[1] !== "\xD8") {
            return self::DEFAULT_ORIENTATION;
        }

        $offset = 2;

        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return self::DEFAULT_ORIENTATION;
            }

            $marker = ord($bytes[$offset + 1]);

            // Padding fill byte: skip a single 0xFF.
            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            // A zero marker is invalid outside entropy-coded data.
            if ($marker === 0x00) {
                return self::DEFAULT_ORIENTATION;
            }

            // Standalone markers carry no payload.
            if (($marker >= 0xD0 && $marker <= 0xD9) || $marker === 0x01) {
                $offset += 2;

                continue;
            }

            $segmentLength = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);

            if ($segmentLength < 2 || $offset + 2 + $segmentLength > $length) {
                return self::DEFAULT_ORIENTATION;
            }

            if ($marker === self::MARKER_APP1) {
                $payload = substr($bytes, $offset + 4, $segmentLength - 2);

                if (str_starts_with($payload, "Exif\x00\x00")) {
                    $orientation = self::tiffOrientation(substr($payload, 6));

                    if ($orientation !== null) {
                        return $orientation;
                    }
                }
            }

            // Start of scan: no EXIF header appears later.
            if ($marker === self::MARKER_SOS) {
                return self::DEFAULT_ORIENTATION;
            }

            $offset += 2 + $segmentLength;
        }

        return self::DEFAULT_ORIENTATION;
    }

    private static function tiffOrientation(string $tiff): ?int
    {
        $length = strlen($tiff);

        if ($length < 8) {
            return null;
        }

        $byteOrder = substr($tiff, 0, 2);
        $littleEndian = $byteOrder === 'II';

        if (! $littleEndian && $byteOrder !== 'MM') {
            return null;
        }

        $read16 = static fn (int $at): int => $littleEndian
            ? unpack('v', substr($tiff, $at, 2))[1]
            : unpack('n', substr($tiff, $at, 2))[1];

        $read32 = static fn (int $at): int => $littleEndian
            ? unpack('V', substr($tiff, $at, 4))[1]
            : unpack('N', substr($tiff, $at, 4))[1];

        if ($read16(2) !== 42) {
            return null;
        }

        $ifdOffset = $read32(4);

        if ($ifdOffset < 8 || $ifdOffset + 2 > $length) {
            return null;
        }

        $entries = $read16($ifdOffset);

        if ($entries < 1 || $entries > self::MAX_IFD_ENTRIES) {
            return null;
        }

        for ($index = 0; $index < $entries; $index++) {
            $entry = $ifdOffset + 2 + ($index * 12);

            if ($entry + 12 > $length) {
                return null;
            }

            if ($read16($entry) !== self::TAG_ORIENTATION) {
                continue;
            }

            $type = $read16($entry + 2);
            $count = $read32($entry + 4);

            // Orientation is a single SHORT (type 3) inline value.
            if ($type !== 3 || $count !== 1) {
                return null;
            }

            $value = $read16($entry + 8);

            return $value >= 1 && $value <= 8 ? $value : null;
        }

        return null;
    }
}
