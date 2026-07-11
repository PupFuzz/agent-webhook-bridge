<?php

namespace App\Bridge\Classifiers;

/**
 * BACK-COMPAT SHIM (roundtable #8): the DL-168 triage-wake is now the
 * `kanban-triage` family of the unified {@see CoordinationClassifier}. This thin
 * subclass preserves the `classifier.class: App\Bridge\Classifiers\KanbanTriageClassifier`
 * target that installs adopted in v0.42.0 — it just pins the default enabled family
 * set to `['kanban-triage']` (an operator's explicit `classifier.config.families`
 * still wins).
 *
 * Prefer configuring `CoordinationClassifier` with `families: [kanban-triage]`
 * directly; this alias remains for continuity. Behavior is identical to the pre-#8
 * standalone classifier: the InboxOnly base stages every kanban `task.*` event, and
 * the kanban-triage family wakes the triage owner on a human-filed, untriaged
 * `task.created`.
 */
class KanbanTriageClassifier extends CoordinationClassifier
{
    /**
     * @return list<string>
     */
    protected function defaultFamilies(): array
    {
        return ['kanban-triage'];
    }
}
