<?php

namespace App\Bridge\Contracts;

use App\Bridge\Dispatch\ClassifyContext;
use App\Bridge\Dispatch\ClassifyResult;

/**
 * Turns a webhook event into intents (surfaced to the agent inbox) and/or
 * reaction targets (automated dispatches). Operators implement this for
 * agent-specific behaviour; the bridge ships InboxOnlyClassifier as the
 * canonical default.
 *
 * The single {@see ClassifyContext} parameter (DL-025) carries event_type,
 * payload, actor, provider, scope_id and the serving agent. It replaced a
 * positional signature precisely because a parameter object is EXTENSIBLE
 * without breaking implementors: adding a field to ClassifyContext never changes
 * this method's signature, so it can never again produce the uncatchable
 * E_COMPILE_ERROR that DL-002 and DL-022's positional additions did. This is the
 * LAST breaking change to classify() — future context needs are new
 * ClassifyContext fields, not new parameters. Classifier instances are shared +
 * cached per class (see ClassifierResolver), so per-event state lives on the
 * context, never on the instance.
 */
interface Classifier
{
    public function classify(ClassifyContext $ctx): ClassifyResult;
}
