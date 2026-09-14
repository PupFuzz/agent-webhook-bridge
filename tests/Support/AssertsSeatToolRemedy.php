<?php

namespace Tests\Support;

/**
 * Assert that the `Run <command>` remedy in a finding names a command a SEAT can run: a
 * declared seat tool's install name, with no `/` in it (DL-385).
 *
 * The assertion this replaces checked that the message CONTAINED `bin/check-channel-snapshot.py`,
 * and so passed on exactly the defect: a checkout-relative path handed to a seat that has no
 * checkout. Resolvability is the property, so it is asserted against `seat-tools.json` — the
 * declaration `bin/seat-pack.py` stages from — rather than against a second spelling of the name.
 * The probe itself never reads that file; this is the join.
 */
trait AssertsSeatToolRemedy
{
    /**
     * Every reason the message's remedy would not resolve on a seat's PATH; empty when it does.
     *
     * @return list<string>
     */
    protected static function seatToolRemedyProblems(string $message): array
    {
        $found = preg_match_all('/\bRun (\S+)/', $message, $matches);
        if ($found !== 1) {
            return ["expected exactly one `Run <command>` remedy in the message, found {$found}"];
        }
        $token = $matches[1][0];

        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/seat-tools.json'), true, 512, JSON_THROW_ON_ERROR);
        $installNames = array_map('basename', $manifest['tools']);

        $problems = [];
        if (str_contains($token, '/')) {
            $problems[] = "remedy `{$token}` contains `/`: a path, which a seat without a bridge checkout does not have";
        }
        if (! in_array($token, $installNames, true)) {
            $problems[] = "remedy `{$token}` is not the install name of a tool declared in seat-tools.json";
        }

        return $problems;
    }

    protected function assertRemedyIsADeclaredSeatTool(string $message): void
    {
        $this->assertSame([], self::seatToolRemedyProblems($message), $message);
    }
}
