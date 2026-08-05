<?php

namespace App\Bridge\Check;

/**
 * A {@see Check} the operator asks for by flag, and which yields nothing when they did
 * not (DL-242 stage 8).
 *
 * WHY THIS IS AN INTERFACE AND NOT A SEVERITY, A FLAG FIELD, OR AN EMPTY-YIELD
 * CONVENTION. The runner cannot infer "not requested" from an empty yield: that is
 * exactly the collapse the resolved opt-in-probe decision refuses, because a check that
 * looked and found nothing and a check nobody asked to look are different facts and
 * only one of them says anything about the install. So the check DECLARES it, and the
 * declaration is the only thing the runner trusts.
 *
 * Registration stays UNCONDITIONAL (plan constraint (a)) — this interface is how an
 * opt-in check reports its own state, never a licence to put an `if` around
 * `register()`. The flag remains a CONSTRUCTOR ARGUMENT.
 */
interface OptInCheck extends Check
{
    /**
     * Whether the operator asked for this check on this invocation.
     *
     * MUST NOT read the install: it answers for the INVOCATION alone (was the flag
     * passed), which is the discriminator the resolved decision turns on. A check that
     * was requested but found nothing applicable to certify has run — it reports that
     * as a finding, and its disposition is {@see CheckDisposition::Reported}.
     */
    public function wasRequested(): bool;
}
