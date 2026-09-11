<?php

namespace App\Models;

use App\Jobs\GenerateServiceContentJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One service the company offers — the vocabulary behind the project
 * form's "Project Type" and the central admin's Services screen. (The
 * landing-page generator keeps its own catalogue here; see the migration.)
 *
 * A project stores this row's `slug` in its project_type column, so the
 * slug is effectively a foreign key: renaming a service is safe, changing
 * its slug is not (existing projects would point at nothing), which is why
 * the API refuses to change one that is in use.
 *
 * Page copy (2026-09-11) ported file-for-file from jpeterson-design's
 * App\Models\Service — same SECTIONS/sectionsMap()/showsSection()/faq
 * backbone as AreaServed already carries here, see that model for the
 * shared contract.
 */
class Service extends Model
{
    protected $fillable = [
        'name', 'slug', 'blurb', 'is_landing_page', 'sort_order',
        'intro', 'what_we_do', 'ideal_for', 'faq', 'sections',
    ];

    /**
     * The pieces of page copy a service can carry, in page order, with the
     * heading each renders under. Every one can be switched off per service
     * (the `sections` map) without deleting the text.
     */
    public const SECTIONS = [
        'intro' => 'Intro',
        'what_we_do' => 'What we do',
        'ideal_for' => 'Who it suits',
        'faq' => 'Questions',
    ];

    protected function casts(): array
    {
        return [
            'is_landing_page' => 'boolean',
            'faq' => 'array',
            'sections' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Service $service) {
            if (empty($service->slug)) {
                $service->slug = static::uniqueSlug($service->name);
            }
            if (! $service->sort_order) {
                $service->sort_order = (int) static::max('sort_order') + 1;
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'service';
        $slug = $base;
        $n = 1;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.++$n;
        }

        return $slug;
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** How many projects use this service — what makes a slug change or a delete unsafe. */
    public function projectsCount(): int
    {
        return Project::where('project_type', $this->slug)->count();
    }

    /** The public service page for this service. */
    public function url(): string
    {
        return url('/services/'.$this->slug);
    }

    /**
     * Show/hide per section, every key present. A stored explicit value
     * (from the admin toggle) always wins; a key absent from `sections`
     * defaults to whether the service actually has content for it —
     * filled() for the text fields, a non-empty faqItems() for "faq".
     *
     * @return array<string, bool>
     */
    public function sectionsMap(): array
    {
        $stored = is_array($this->sections) ? $this->sections : [];

        return collect(self::SECTIONS)->mapWithKeys(function ($label, $key) use ($stored) {
            if (array_key_exists($key, $stored)) {
                return [$key => (bool) $stored[$key]];
            }

            $hasContent = $key === 'faq' ? $this->faqItems() !== [] : filled($this->{$key});

            return [$key => $hasContent];
        })->all();
    }

    /** Does the page render this section: switched on, and there is something to show. */
    public function showsSection(string $key): bool
    {
        if (! array_key_exists($key, self::SECTIONS) || ! $this->sectionsMap()[$key]) {
            return false;
        }

        return $key === 'faq' ? $this->faqItems() !== [] : filled($this->{$key});
    }

    /**
     * The FAQ as a clean list of question/answer pairs.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function faqItems(): array
    {
        return self::normaliseFaq($this->faq);
    }

    /** @return list<array{question: string, answer: string}> */
    public static function normaliseFaq(mixed $faq): array
    {
        if (! is_array($faq)) {
            return [];
        }

        $out = [];
        foreach ($faq as $item) {
            $q = trim((string) ($item['question'] ?? $item['q'] ?? ''));
            $a = trim((string) ($item['answer'] ?? $item['a'] ?? ''));
            if ($q !== '' && $a !== '') {
                $out[] = ['question' => $q, 'answer' => $a];
            }
        }

        return $out;
    }

    /**
     * slug => name, the shape Project::projectTypes() returned before this
     * table existed, so every caller of that vocabulary keeps working.
     *
     * @return array<string, string>
     */
    public static function vocabulary(): array
    {
        return static::ordered()->pluck('name', 'slug')->all();
    }

    public function toApiArray(): array
    {
        $flag = Cache::get(GenerateServiceContentJob::flagKey((int) $this->id));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'blurb' => $this->blurb,
            'is_landing_page' => $this->is_landing_page,
            'sort_order' => $this->sort_order,
            'projects_count' => $this->projectsCount(),
            'intro' => $this->intro,
            'what_we_do' => $this->what_we_do,
            'ideal_for' => $this->ideal_for,
            'faq' => $this->faqItems(),
            // Per-section show/hide, every key present — see SECTIONS.
            'sections' => $this->sectionsMap(),
            'section_labels' => self::SECTIONS,
            'public_url' => $this->url(),
            // Set while GenerateServiceContentJob is writing the page; the admin polls it.
            'generating' => is_array($flag) && empty($flag['error']),
            'generation_error' => is_array($flag) ? ($flag['error'] ?? null) : null,
        ];
    }
}
