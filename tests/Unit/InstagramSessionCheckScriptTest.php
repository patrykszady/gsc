<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * scripts/instagram-check-session.mjs opens ONE tab, every run.
 *
 * A Chrome that Puppeteer launches on a persistent profile reopens every tab
 * of the profile's last run and each run of the check left two more behind
 * (measured: 1, 3, 5, 7, 9 … tabs at launch, clean close or SIGKILL alike).
 * After three months of nightly checks the 02:35 browser came up with ~100
 * Instagram tabs — 110 processes, 5.8 GB inside a minute — and
 * chrome:reap-orphans killed it every night before it could answer. The
 * check forgets the profile's saved session before it launches
 * (scripts/lib/chrome-profile.mjs), refuses a browser that still comes up
 * with a crowd of tabs, and says why before it does anything else.
 *
 * No browser is started by these tests: the node tests they run point the
 * script at /bin/false as its Chrome.
 */
class InstagramSessionCheckScriptTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/scripts/instagram-check-session.mjs');
    }

    public function test_the_saved_session_is_forgotten_before_chrome_is_launched(): void
    {
        $source = $this->source();

        $forget = strpos($source, 'forgetSavedSession(args.userDataDir)');
        $launch = strpos($source, 'puppeteer.launch(');

        $this->assertNotFalse($forget, 'The check must forget the profile\'s saved tabs (scripts/lib/chrome-profile.mjs).');
        $this->assertLessThan($launch, $forget);
    }

    /**
     * The check is not the only script on storage/app/instagram-puppeteer:
     * the publisher, the location picker, the location scraper and the
     * sign-in window each left their tabs behind for the next launch too.
     */
    public function test_every_script_that_opens_the_instagram_profile_forgets_its_saved_tabs_first(): void
    {
        $scripts = array_merge(
            glob(dirname(__DIR__, 2).'/scripts/instagram-*.mjs') ?: [],
            [dirname(__DIR__, 2).'/scripts/scrape-instagram-location.mjs'],
        );

        $this->assertGreaterThanOrEqual(5, count($scripts));

        foreach ($scripts as $script) {
            $source = (string) file_get_contents($script);
            $forget = strpos($source, 'forgetSavedSession(args.userDataDir)');
            $launch = strpos($source, 'puppeteer.launch(');

            $this->assertNotFalse($launch, basename($script).' no longer launches Chrome — drop it from this list.');
            $this->assertNotFalse($forget, basename($script).' launches Chrome on the Instagram profile without forgetting its saved tabs.');
            $this->assertLessThan($launch, $forget, basename($script).' forgets the saved tabs only after launching.');
        }
    }

    public function test_tabs_that_could_not_be_removed_stop_the_check_before_chrome_is_launched(): void
    {
        $source = $this->source();

        $refusal = strpos($source, "reason: 'saved_session_not_cleared'");

        $this->assertNotFalse($refusal);
        $this->assertLessThan(strpos($source, 'puppeteer.launch('), $refusal);
    }

    public function test_a_browser_that_came_up_with_a_crowd_of_tabs_is_refused_before_instagram_is_loaded(): void
    {
        $source = $this->source();

        $launch = strpos($source, 'puppeteer.launch(');
        $refusal = strpos($source, "reason: 'session_restored'");
        $visit = strpos($source, "page.goto('https://www.instagram.com/'");

        $this->assertNotFalse($refusal);
        $this->assertGreaterThan($launch, $refusal);
        $this->assertLessThan($visit, $refusal);
    }

    /** A close that hangs must not swallow the reason: the answer goes out first. */
    public function test_a_failure_is_printed_before_the_browser_is_closed(): void
    {
        $failurePath = substr($this->source(), (int) strpos($this->source(), '} catch (e) {'));

        $said = strpos($failurePath, 'emit({ ok: false, error:');
        $close = strpos($failurePath, 'browser.close()');

        $this->assertNotFalse($said);
        $this->assertNotFalse($close);
        $this->assertLessThan($close, $said);
    }

    /** The deadline line names itself too, so a run stopped at its limit is not a bare null. */
    public function test_the_deadline_prints_its_reason_before_it_exits(): void
    {
        $source = $this->source();

        $said = strpos($source, "reason: 'deadline'");
        $exit = strpos($source, 'process.exit(124)');

        $this->assertNotFalse($said);
        $this->assertLessThan($exit, $said);
    }

    public function test_the_profile_helper_and_the_check_pass_their_node_tests(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('node is not installed here.');
        }

        $root = dirname(__DIR__, 2);
        $process = new Process([
            $node,
            '--test',
            $root.'/scripts/tests/chrome-profile.test.mjs',
            $root.'/scripts/tests/instagram-check-session.test.mjs',
        ], $root);
        $process->setTimeout(120);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertMatchesRegularExpression('/^# fail 0$/m', $process->getOutput());
    }
}
