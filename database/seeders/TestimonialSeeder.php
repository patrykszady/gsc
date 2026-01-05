<?php

namespace Database\Seeders;

use App\Models\Testimonial;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestimonialSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = database_path('data/gs-construction-reviews.csv');
        if (! is_file($csvPath)) {
            return;
        }

        $seen = [];

        DB::transaction(function () use ($csvPath, &$seen): void {
            $handle = fopen($csvPath, 'r');
            if ($handle === false) {
                return;
            }

            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                fclose($handle);
                return;
            }

            $headers = array_map(static fn ($h) => trim((string) $h), $headers);

            while (($row = fgetcsv($handle)) !== false) {
                if (! is_array($row) || count($row) === 0) {
                    continue;
                }

                $normalizedRow = array_slice($row, 0, count($headers));
                $data = array_combine($headers, array_pad($normalizedRow, count($headers), null));
                if (! is_array($data)) {
                    continue;
                }

                $reviewerName = trim((string) ($data['Reviewer Name'] ?? ''));
                $reviewDescription = trim((string) ($data['Review Description'] ?? ''));

                if ($reviewerName === '' || $reviewDescription === '') {
                    continue;
                }

                $key = $reviewerName . '|' . substr($reviewDescription, 0, 120);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $reviewLocation = trim((string) ($data['Project Location'] ?? ''));
                $reviewUrl = trim((string) ($data['Review URL'] ?? ''));
                $reviewImage = trim((string) ($data['Review Image'] ?? ''));
                $reviewDateRaw = trim((string) ($data['Review Date'] ?? ''));

                $reviewDate = null;
                if ($reviewDateRaw !== '') {
                    try {
                        $cleaned = preg_replace('/\s+GMT.*$/', '', $reviewDateRaw);
                        $reviewDate = Carbon::parse($cleaned)->toDateString();
                    } catch (\Throwable) {
                        $reviewDate = null;
                    }
                }

                // Prevent duplicates when the CSV contains the same review twice (e.g. one row with URL, one without).
                $dedupeKey = $reviewerName . '|' . ($reviewDate ?? substr($reviewDescription, 0, 120));
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $payload = [
                    'reviewer_name' => $reviewerName,
                    'project_location' => $reviewLocation !== '' ? $reviewLocation : null,
                    'review_description' => $reviewDescription,
                    'review_date' => $reviewDate,
                    'review_url' => $reviewUrl !== '' ? $reviewUrl : null,
                    'review_image' => $reviewImage !== '' ? $reviewImage : null,
                ];

                $existing = null;

                // 1) Best key: review_url.
                if ($reviewUrl !== '') {
                    $existing = Testimonial::query()->where('review_url', $reviewUrl)->first();
                }

                // 2) Stable key: reviewer_name + review_date.
                if (! $existing && $reviewDate !== null) {
                    $existing = Testimonial::query()
                        ->where('reviewer_name', $reviewerName)
                        ->whereDate('review_date', $reviewDate)
                        ->first();
                }

                // 3) Fallback: same reviewer + matching description.
                if (! $existing) {
                    $prefix = substr($reviewDescription, 0, 80);
                    $existing = Testimonial::query()
                        ->where('reviewer_name', $reviewerName)
                        ->where(function ($q) use ($reviewDescription, $prefix) {
                            $q->where('review_description', $reviewDescription)
                                ->orWhere('review_description', 'like', $prefix . '%');
                        })
                        ->first();
                }

                if ($existing) {
                    $existing->fill($payload)->save();
                } else {
                    Testimonial::create($payload);
                }
            }

            fclose($handle);
        });
    }
}
