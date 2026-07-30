<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckDisposition;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Support\Finding;
use App\Bridge\Support\UrlValidator;
use Throwable;

/**
 * The per-install endpoint URLs, migrated out of `CheckCommand::handle()`
 * (DL-242 stage 6).
 *
 * TWO URLS UNDER TWO DIFFERENT FLOORS, and the difference is the point (#3574): the
 * receiver's own base URL need only be a well-formed http(s) URL, while the kanban API
 * base URL carries the API token and the provision-time HMAC secret, so cleartext http
 * would put both on the wire and it is held to https. One check rather than two because
 * they are one config concern rendered by one authority; the field name in each message
 * is what distinguishes them for the operator.
 *
 * SILENT WHEN BOTH ARE WELL-FORMED, and silent when either is unset — an install that has
 * not been provisioned yet has no endpoints, which is not a fault. Yielding nothing is
 * how a conditionally-silent leg migrates under the byte-identical output contract. Stage 8
 * did NOT convert that silence into a finding — measurement showed most registered checks
 * are silent on a healthy install — it made the silence COUNTED, as
 * {@see CheckDisposition::Silent} in the run inventory.
 *
 * THE VERDICT TEXTS ARE `UrlValidator`'s. It composes every refusal — whitespace, not a
 * URL, wrong scheme, no host, and the https floor's own longer explanation — and this
 * check renders the thrown message unchanged.
 *
 * ONLY THE PLAIN-HTTP LEG HAS A GOLDEN FIXTURE (a non-URL receiver base). Nothing in the
 * fixture set has ever rendered the https-floor refusal, so a green golden run is not
 * evidence for the leg that guards the token — `InstallEndpointUrlsCheckTest` asserts
 * both, and asserts them as a pair: the same `http://` URL that the receiver leg accepts
 * must be refused by the kanban leg, which is what proves the two floors are not
 * interchangeable.
 *
 * @see CheckSlot::Providers
 */
final class InstallEndpointUrlsCheck implements Check
{
    public function id(): string
    {
        return 'install.endpoint_urls';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        foreach ([
            'receiver_base_url' => ['url' => (string) config('bridge.receiver_base_url'), 'secure' => false],
            // secret-bearing (token + provision-time HMAC secret) — https floor (#3574)
            'providers.kanban.api_base_url' => ['url' => (string) config('bridge.providers.kanban.api_base_url'), 'secure' => true],
        ] as $field => $spec) {
            if ($spec['url'] === '') {
                continue;
            }
            try {
                $spec['secure']
                    ? UrlValidator::secureHttpUrl($spec['url'], "bridge.{$field}")
                    : UrlValidator::httpUrl($spec['url'], "bridge.{$field}");
            } catch (Throwable $e) {
                yield Finding::fail($e->getMessage());
            }
        }
    }
}
