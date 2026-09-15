<?php

namespace Tests\Support;

use App\Bridge\Support\SecretScrubber;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Assert;

/**
 * A kanban-shaped 422 whose body echoes a credentialed URL with the PASSWORD STRADDLING
 * `RequestException::$truncateAt` — the body shape card#9486 measured leaking through
 * "scrub `getMessage()`" (card#9278 review R2).
 *
 * ⛔ IT PROVES ITS OWN PRECONDITION. A fixture whose cut drifted off the password (a Laravel
 * default moving, a longer prefix) would let every consumer pass vacuously, so {@see self::make()}
 * asserts the OLD route still leaks the password's head before handing the exception over.
 */
final class StraddlingRequestException
{
    /** Synthetic. */
    public const PASSWORD = 'canary-pw-9486'; // gitleaks:allow — synthetic canary, not a credential

    /**
     * The part of {@see self::PASSWORD} left in front of the cut, and what consumers assert
     * ABSENT: the leak this fixture exists for is a FRAGMENT, so asserting on the whole value
     * would pass over exactly that leak.
     */
    public const STEM = 'canary';

    /** The response body alone, for a test that fakes the HTTP call rather than the exception. */
    public static function body(): string
    {
        $body = static fn (string $message): string => (string) json_encode([
            'message' => $message,
            'errors' => ['url' => ['https://svc:'.self::PASSWORD.'@bridge.example.com/webhooks/kanban?b=5']],
        ]);
        $passwordAt = (int) strpos($body(''), self::PASSWORD);

        return $body(str_pad('The url field is invalid.', RequestException::$truncateAt - strlen(self::STEM) - $passwordAt));
    }

    public static function make(): RequestException
    {
        $e = new RequestException(new Response(new GuzzleResponse(422, ['Content-Type' => 'application/json'], self::body())));

        Assert::assertStringContainsString(
            'svc:'.self::STEM.' ',
            SecretScrubber::text($e->getMessage()),
            'fixture drift: the truncation no longer cuts inside the password, so nothing here exercises the straddle',
        );
        Assert::assertStringNotContainsString(self::PASSWORD, $e->getMessage(), 'fixture drift: the whole password is inside the cut');

        return $e;
    }
}
