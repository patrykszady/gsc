<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Read-only dashboard that surfaces the weekly SEO markdown reports produced
 * by the seo:* scheduled commands. Operators can also re-run a report
 * on-demand (synchronous artisan call; intended for small/fast jobs).
 */
#[Layout('components.layouts.admin')]
#[Title('SEO Reports')]
class SeoReports extends Component
{
    public ?string $active = null;

    public ?string $flash = null;

    /**
     * Registry of reports rendered in the dashboard. Keys are file names
     * (without extension) under storage/app/reports/; each value provides
     * the display label and the artisan command that regenerates it.
     *
     * @var array<string, array{label:string, command:string, description:string}>
     */
    protected array $reports = [
        'content-decay' => [
            'label' => 'Content decay',
            'command' => 'seo:content-decay --markdown',
            'description' => 'Pages losing clicks or position week over week.',
        ],
        'content-gap' => [
            'label' => 'Content gap (rank 8–20)',
            'command' => 'seo:content-gap --markdown',
            'description' => 'Striking-distance queries clustered into content briefs.',
        ],
        'cwv-template' => [
            'label' => 'CWV by template',
            'command' => 'seo:cwv-template --markdown',
            'description' => 'p75 LCP/INP/CLS per page template with regression alerts.',
        ],
        'gbp-parity' => [
            'label' => 'GBP / local-SEO parity',
            'command' => 'seo:gbp-parity --markdown',
            'description' => 'NAP consistency and Google Business Profile ↔ site service parity.',
        ],
        'internal-link-suggest' => [
            'label' => 'Internal-link suggestions',
            'command' => 'seo:internal-link-suggest --markdown',
            'description' => 'Unlinked plain-text mentions of other pages’ anchors.',
        ],
        'backlinks-monitor' => [
            'label' => 'Backlinks / mentions',
            'command' => 'seo:backlinks-monitor --markdown',
            'description' => 'New and lost referring hosts (via SerpApi).',
        ],
        'schema-audit' => [
            'label' => 'Schema audit',
            'command' => 'seo:schema-audit --markdown',
            'description' => 'JSON-LD coverage and validity sweep.',
        ],
        'area-pages-audit' => [
            'label' => 'Area pages (thin/dup)',
            'command' => 'seo:area-pages-audit --markdown',
            'description' => 'Thin pages and near-duplicate clusters across per-area landing pages.',
        ],
        'health-check' => [
            'label' => 'Local SEO health-check',
            'command' => 'seo:health-check --markdown',
            'description' => 'Composite 0–100 score per URL across title, meta, H1, alt, links, schema, canonical, word count.',
        ],
        'clarity-health' => [
            'label' => 'Clarity health',
            'command' => 'seo:clarity-health --markdown',
            'description' => 'Clarity API/config status, last sync freshness, and latest behavioral metrics snapshot.',
        ],
    ];

    public function mount(?string $report = null): void
    {
        if ($report !== null && isset($this->reports[$report])) {
            $this->active = $report;
        }
    }

    public function open(string $key): void
    {
        if (! isset($this->reports[$key])) {
            return;
        }
        $this->active = $key;
    }

    public function regenerate(string $key): void
    {
        if (! isset($this->reports[$key])) {
            return;
        }
        // Synchronous re-run; these commands are read-mostly and fast.
        try {
            Artisan::call($this->reports[$key]['command']);
            $this->flash = $this->reports[$key]['label'] . ' regenerated.';
        } catch (\Throwable $e) {
            $this->flash = 'Failed to regenerate: ' . $e->getMessage();
        }
        $this->active = $key;
        // Bust the computed cache so the file list / body re-read from disk.
        unset($this->files, $this->activeHtml);
    }

    /**
     * @return array<int, array{key:string,label:string,description:string,command:string,exists:bool,size:?int,mtime:?\Carbon\CarbonInterface,age:?string}>
     */
    #[Computed]
    public function files(): array
    {
        $disk = Storage::disk('local');
        $out = [];
        foreach ($this->reports as $key => $meta) {
            $path = "reports/{$key}.md";
            $exists = $disk->exists($path);
            $size = $exists ? $disk->size($path) : null;
            $mtimeTs = $exists ? $disk->lastModified($path) : null;
            $mtime = $mtimeTs ? Carbon::createFromTimestamp($mtimeTs) : null;
            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'command' => $meta['command'],
                'exists' => $exists,
                'size' => $size,
                'mtime' => $mtime,
                'age' => $mtime?->diffForHumans(),
            ];
        }
        return $out;
    }

    #[Computed]
    public function activeHtml(): ?string
    {
        if ($this->active === null || ! isset($this->reports[$this->active])) {
            return null;
        }
        $path = "reports/{$this->active}.md";
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return '<p class="text-zinc-500">Report not yet generated. Click <strong>Run now</strong> to create it.</p>';
        }
        $md = (string) $disk->get($path);
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        return (string) $converter->convert($md);
    }

    public function render()
    {
        return view('livewire.admin.seo-reports');
    }
}
