<?php

namespace App\Console\Commands;

use App\Console\Commands\Seo\KitReportCommand;
use SsSystems\Platform\Reports\GbpParityReport;

/**
 * Local SEO parity audit — thin wrapper. NAP checks and GBP/service-page
 * parity live in the kit's GbpParityReport now; see
 * vendor/ss-systems/platform-kit/docs/REPORTS-PORTING.md.
 */
class SeoGbpParity extends KitReportCommand
{
    protected $signature = 'seo:gbp-parity
        {--landing-pages=/,/contact,/about : CSV of paths to check for NAP}
        {--markdown : Save report to storage/app/reports/gbp-parity.md}';

    protected $description = 'Audit NAP consistency and Google Business Profile / service-page parity.';

    public function handle(GbpParityReport $report): int
    {
        $result = $report->generate([
            'landing_pages' => (string) $this->option('landing-pages'),
        ]);

        $this->info('Expected phone (normalized): '.($result->data['expected_phone'] ?: '(none configured)'));
        $this->info('Expected address fragment: '.($result->data['expected_address'] !== '' ? $result->data['expected_address'] : '(none configured)'));

        $napRows = [];
        foreach ($result->data['nap'] as $url => $r) {
            $napRows[] = ['url' => $url, 'phone' => $r['phone'], 'phone_ok' => $r['phone_ok'], 'address_ok' => $r['address_ok'], 'note' => $r['note']];
        }
        $this->renderRows('NAP per landing page', $napRows, ['url', 'phone', 'phone_ok', 'address_ok', 'note'], ['URL', 'Phone(s)', 'Phone match', 'Addr match', 'Note']);

        $this->newLine();
        $this->line('<fg=cyan>--- Service parity ---</>');
        $this->line('  Pillars covered ('.count($result->data['pillars_covered']).'): '.(empty($result->data['pillars_covered']) ? '—' : implode(', ', $result->data['pillars_covered'])));
        $this->line('  Pillars MISSING ('.count($result->data['pillars_missing']).'): '.(empty($result->data['pillars_missing']) ? '—' : implode(', ', $result->data['pillars_missing'])));
        $this->line('  Sub-services rolled up ('.count($result->data['subs_rolled']).'): '.(empty($result->data['subs_rolled']) ? '—' : implode(', ', array_keys($result->data['subs_rolled']))));
        $this->line('  Sub-services ORPHANED ('.count($result->data['subs_orphan']).'): '.(empty($result->data['subs_orphan']) ? '—' : implode(', ', array_keys($result->data['subs_orphan']))));
        $this->line('  On site, missing in GBP ('.count($result->data['site_only']).'): '.(empty($result->data['site_only']) ? '—' : implode(', ', $result->data['site_only'])));

        if ($this->option('markdown')) {
            $this->saveMarkdown(GbpParityReport::key(), $result->markdown, 'Saved: storage/app/reports/gbp-parity.md');
        }

        if (! empty($result->data['issues'])) {
            logger()->warning('seo:gbp-parity issues', ['count' => count($result->data['issues']), 'first' => array_slice($result->data['issues'], 0, 5)]);
        }

        return self::SUCCESS;
    }
}
