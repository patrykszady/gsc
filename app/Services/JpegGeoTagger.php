<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use lsolesen\pel\PelDataWindow;
use lsolesen\pel\PelEntryAscii;
use lsolesen\pel\PelEntryByte;
use lsolesen\pel\PelEntryRational;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTag;
use lsolesen\pel\PelTiff;

/**
 * Inject EXIF GPS coordinates into a JPEG byte string.
 *
 * Used before pushing project photos to Google Business Profile so the
 * uploaded media carries genuine location metadata — a known soft signal
 * for local photo prominence on Google Maps.
 *
 * Only the GPS IFD is added; image bytes are untouched.
 */
class JpegGeoTagger
{
    /**
     * Return JPEG bytes with GPS EXIF written. Returns original bytes on error.
     */
    public function withGps(string $jpegBytes, float $latitude, float $longitude, ?\DateTimeInterface $when = null): string
    {
        try {
            $data = new PelDataWindow($jpegBytes);
            if (! PelJpeg::isValid($data)) {
                return $jpegBytes;
            }

            $jpeg = new PelJpeg();
            $jpeg->load($data);

            $exif = $jpeg->getExif();
            if ($exif === null) {
                $exif = new PelExif();
                $tiff = new PelTiff();
                $exif->setTiff($tiff);
                $jpeg->setExif($exif);
            } else {
                $tiff = $exif->getTiff();
                if ($tiff === null) {
                    $tiff = new PelTiff();
                    $exif->setTiff($tiff);
                }
            }

            $ifd0 = $tiff->getIfd();
            if ($ifd0 === null) {
                $ifd0 = new PelIfd(PelIfd::IFD0);
                $tiff->setIfd($ifd0);
            }

            // Replace any existing GPS sub-IFD.
            $gpsIfd = new PelIfd(PelIfd::GPS);
            $ifd0->addSubIfd($gpsIfd);

            // GPSVersionID = 2.3.0.0
            $gpsIfd->addEntry(new PelEntryByte(PelTag::GPS_VERSION_ID, 2, 3, 0, 0));

            // Latitude
            $latRef = $latitude >= 0 ? 'N' : 'S';
            $gpsIfd->addEntry(new PelEntryAscii(PelTag::GPS_LATITUDE_REF, $latRef));
            $gpsIfd->addEntry(new PelEntryRational(
                PelTag::GPS_LATITUDE,
                ...$this->degreesToRationals(abs($latitude))
            ));

            // Longitude
            $lngRef = $longitude >= 0 ? 'E' : 'W';
            $gpsIfd->addEntry(new PelEntryAscii(PelTag::GPS_LONGITUDE_REF, $lngRef));
            $gpsIfd->addEntry(new PelEntryRational(
                PelTag::GPS_LONGITUDE,
                ...$this->degreesToRationals(abs($longitude))
            ));

            // Timestamp (UTC) — optional but improves trustworthiness.
            $when ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $utc = (clone $when)->setTimezone(new \DateTimeZone('UTC'));
            $gpsIfd->addEntry(new PelEntryAscii(PelTag::GPS_DATE_STAMP, $utc->format('Y:m:d')));
            $gpsIfd->addEntry(new PelEntryRational(
                PelTag::GPS_TIME_STAMP,
                [(int) $utc->format('H'), 1],
                [(int) $utc->format('i'), 1],
                [(int) $utc->format('s'), 1],
            ));

            return $jpeg->getBytes();
        } catch (\Throwable $e) {
            Log::warning('JpegGeoTagger: failed to inject GPS', [
                'error' => $e->getMessage(),
                'lat' => $latitude,
                'lng' => $longitude,
            ]);
            return $jpegBytes;
        }
    }

    /**
     * Decimal degrees → three EXIF rationals (degrees, minutes, seconds*1000).
     *
     * @return array{0: array{0:int,1:int}, 1: array{0:int,1:int}, 2: array{0:int,1:int}}
     */
    protected function degreesToRationals(float $deg): array
    {
        $d = (int) floor($deg);
        $minFloat = ($deg - $d) * 60;
        $m = (int) floor($minFloat);
        $s = ($minFloat - $m) * 60;

        return [
            [$d, 1],
            [$m, 1],
            [(int) round($s * 1000), 1000],
        ];
    }

    /**
     * Quick check whether a JPEG already carries GPS EXIF (so we can skip
     * re-tagging cached derivatives).
     */
    public function hasGps(string $jpegBytes): bool
    {
        if (! function_exists('exif_read_data')) {
            return false;
        }
        try {
            $tmp = 'data://image/jpeg;base64,' . base64_encode($jpegBytes);
            $data = @exif_read_data($tmp, 'GPS', false, false);
            return is_array($data) && (isset($data['GPSLatitude']) || isset($data['GPS']['GPSLatitude']));
        } catch (\Throwable) {
            return false;
        }
    }
}
