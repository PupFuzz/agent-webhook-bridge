<?php

namespace Tests\Support;

use App\Bridge\Tools\ToolCallBody;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * The WORDS {@see ToolCallBody::jsonType()} can name a body's type with, read out of its source
 * every run (card#10106 review).
 *
 * ⭐ WHY THIS EXISTS AT ALL. `unparseableBodies()` declares itself the control set for every arm
 * of that method — a true claim with nothing keeping it true, which is the shape canon #16 names.
 * The declaration lives in the TEST, while the person adding an arm is editing `ToolCallBody.php`;
 * without a guard, the next arm ships an operator-facing refusal that no row exercises, silently
 * and at green. This class is the guard's reader half: the population is DERIVED from the method,
 * never listed here, so an arm joins it by existing.
 *
 * ⛔ IT REFUSES RATHER THAN RETURNING A SHORT LIST. An arm whose body is not a plain string
 * literal, a missing method, or a second `match` in it are all states where a quiet answer would
 * be a control set measured over a population the reader could not see — so each throws and names
 * itself. An empty result here is never "no arms".
 */
final class JsonTypeArms
{
    public const SUBJECT = 'app/Bridge/Tools/ToolCallBody.php';

    private const METHOD = 'jsonType';

    /**
     * Every distinct word an arm returns, in source order.
     *
     * @return list<string>
     */
    public static function words(): array
    {
        $path = base_path(self::SUBJECT);
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException(self::SUBJECT.' is unreadable at '.$path);
        }

        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $methods = array_values(array_filter(
            (new NodeFinder)->findInstanceOf($ast, Node\Stmt\ClassMethod::class),
            fn (Node\Stmt\ClassMethod $m): bool => $m->name->toString() === self::METHOD,
        ));
        if (count($methods) !== 1) {
            throw new RuntimeException(self::SUBJECT.' declares '.count($methods).' '.self::METHOD.'() methods; this reader is written for exactly one');
        }

        $matches = (new NodeFinder)->findInstanceOf($methods[0], Node\Expr\Match_::class);
        if (count($matches) !== 1) {
            throw new RuntimeException(self::METHOD.'() holds '.count($matches).' match expressions; this reader is written for exactly one');
        }

        $words = [];
        foreach ($matches[0]->arms as $arm) {
            if (! $arm->body instanceof Node\Scalar\String_) {
                throw new RuntimeException(self::METHOD.'() has an arm returning something other than a string literal — this reader cannot name what it answers, and a silent skip would drop it from the control set');
            }
            $words[] = $arm->body->value;
        }

        return array_values(array_unique($words));
    }
}
