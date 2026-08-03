<?php

namespace Tests\Unit;

use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Real strings taken from the leak this class was written for: a 39MB
 * `laravel.log` that had been downloaded and left in `public/`, carrying the
 * proxy password, both captcha keys and the IPRoyal password — all of them
 * pulled from Symfony Process exception messages, which embed the full
 * command line.
 */
class SecretRedactorTest extends TestCase
{
    public function test_it_strips_credentials_from_a_process_command_line(): void
    {
        $msg = 'The process "\'/bin/bash\' \'scripts/yelp-run-locked.sh\' \'node\' '
             . '\'scripts/yelp-login.mjs\' \'--mode=login\' \'--email=owner@example.com\' '
             . '\'--password=Hunter2#\' \'--proxy=http://user123:secretpass@na.proxy.example.com:2334\' '
             . '\'--twocaptcha-key=8633675fe02d4fe90eed276d8651cef4\'" exceeded the timeout of 240 seconds.';

        $out = SecretRedactor::redact($msg);

        $this->assertStringNotContainsString('Hunter2#', $out);
        $this->assertStringNotContainsString('secretpass', $out);
        $this->assertStringNotContainsString('8633675fe02d4fe90eed276d8651cef4', $out);

        // Non-secret context must survive — a redacted log still has to be useful.
        $this->assertStringContainsString('--mode=login', $out);
        $this->assertStringContainsString('owner@example.com', $out);
        $this->assertStringContainsString('exceeded the timeout', $out);
    }

    public function test_it_strips_credentials_embedded_in_a_url(): void
    {
        $out = SecretRedactor::redact('connecting via http://uad6419c956fd05c7:uad6419c@na.proxy.2captcha.com:2334');

        $this->assertStringNotContainsString('uad6419c@', $out);
        $this->assertStringContainsString('na.proxy.2captcha.com:2334', $out);
    }

    public function test_it_strips_known_key_shapes_even_without_a_flag(): void
    {
        // Assembled rather than written out. A literal of the right shape is
        // indistinguishable from a live credential to a scanner — GitHub's
        // push protection rejected this file when the Google case was pasted
        // in verbatim, which is exactly the behaviour you want from it.
        $cases = [
            'AIza' . str_repeat('A', 35),
            'sk-proj-' . str_repeat('b', 32),
            'GOCSPX-' . str_repeat('c', 16),
            'key-' . str_repeat('d', 32),
        ];

        foreach ($cases as $secret) {
            $out = SecretRedactor::redact("upstream said: {$secret} is invalid");
            $this->assertStringNotContainsString($secret, $out, "leaked: {$secret}");
            $this->assertStringContainsString('[REDACTED]', $out);
        }
    }

    public function test_it_redacts_nested_log_context(): void
    {
        $out = SecretRedactor::context([
            'image_id' => 252,
            'error' => '--password=Hunter2# failed',
            'nested' => ['stderr' => 'used --twocaptcha-key=8633675fe02d4fe90eed276d8651cef4'],
        ]);

        $this->assertSame(252, $out['image_id']);
        $this->assertStringNotContainsString('Hunter2#', $out['error']);
        $this->assertStringNotContainsString('8633675fe02d4fe90eed276d8651cef4', $out['nested']['stderr']);
    }

    public function test_empty_and_null_are_safe(): void
    {
        $this->assertSame('', SecretRedactor::redact(null));
        $this->assertSame('', SecretRedactor::redact(''));
    }
}
