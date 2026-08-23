<?php

namespace Tests\Feature\Workflows;

use Tests\Support\DocRefGateHarness;
use Tests\TestCase;

/**
 * Drives the REAL `bin/check-doc-refs.php` over synthetic repo trees for RULE 1's MEMBER leg —
 * the half of a `Class::member` citation that the FQCN rule used to strip and throw away.
 *
 * WHY THIS FILE EXISTS. A guard that answers about the class and not the member reports on the
 * easy half of the citation: `DlTokenGrammar::sole()` and a citation naming a method that does
 * not exist were the same verdict, because only the class was ever read.
 *
 * NOT card#6025's instance 3, which this leg does NOT close. That one was a bare
 * `correlationRefs()` carrying no class and no `::` — never a token this script reads, before
 * the change or after it. The strip was not what hid it, and no vector here can pretend
 * otherwise.
 *
 * ITS SHAPE IS THE ONE THE CITATION LINT ADOPTED — every ACCEPTANCE is paired with a mutated
 * vector that must be REJECTED. An acceptance on its own is indistinguishable from an inert
 * harness, and this rule's exemptions (a rejected alternative, an ancestry that leaves `app/`,
 * an ambiguous basename, an enum's synthesised methods) are all acceptances, which is exactly
 * where the citation rule's first evidence was found to have holes. The pairing is not free: the
 * `app/` scope bound shipped here as a lone acceptance, and it passed with the member leg deleted
 * outright. `Widget::class` is the one acceptance with no twin, and deliberately — it names no
 * member for a mutation to make phantom.
 */
class DocRefMemberLintTest extends TestCase
{
    use DocRefGateHarness;

    /** A class with one real method, used as the resolvable target of a citation. */
    private const SUBJECT = <<<'PHP'
<?php

namespace App\Bridge\Support;

final class Widget
{
    public const ID = 'widget';

    public function __construct(public readonly string $label) {}

    public function stamp(): void {}
}
PHP;

    protected function tearDown(): void
    {
        $this->removeGateTrees();

        parent::tearDown();
    }

    /**
     * The member leg's specialisation of the harness's `assertRejected()`: same verdict, plus the
     * assertion that the rejection came from THIS leg rather than the file/FQCN leg or a sibling
     * rule. It reads the output of the run the harness already made, so both assertions speak for
     * one verdict.
     *
     * @param  array<string, string>  $files
     */
    private function assertMemberRejected(array $files, string $expectedAt, string $why): void
    {
        $out = $this->assertRejected($files, $expectedAt, $why);

        $this->assertStringContainsString('declares no', $out,
            "{$why}\nthe failure must come from the MEMBER leg, not the file/FQCN leg or a sibling rule:\n{$out}");
    }

    /**
     * THE CONTROL every acceptance below is read against: a tree holding the subject class and
     * a citation of its REAL method must pass, or an acceptance proves only that the harness
     * is inert.
     */
    public function test_a_citation_of_a_declared_member_is_accepted_and_a_phantom_beside_it_is_not(): void
    {
        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE.md' => "The stamp is written by `Widget::stamp()`.\n",
        ], 'a citation of a method the class really declares must pass');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE.md' => "The stamp is written by `Widget::brand()`.\n",
        ], 'CLAUDE.md:1', 'the same shape naming a method the class does not declare must fail');
    }

    /**
     * The FQCN spelling is the one Rule 1 already read — and threw the member away on. Driven
     * separately from the bare spelling because they take different resolution paths.
     */
    public function test_the_member_of_an_app_fqcn_is_checked_rather_than_stripped(): void
    {
        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_ARCHITECTURE.md' => "See `App\\Bridge\\Support\\Widget::stamp()`.\n",
        ], 'the FQCN spelling of a real method must pass');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_ARCHITECTURE.md' => "See `App\\Bridge\\Support\\Widget::brand()`.\n",
        ], 'CLAUDE_ARCHITECTURE.md:1', 'a phantom member under a resolving FQCN is the exact shape the strip hid');
    }

    /**
     * Constants, promoted properties and enum cases are members too — a leg that only knew
     * `function` would have reported three quarters of the citations in these docs as phantoms.
     */
    public function test_constants_properties_and_enum_cases_resolve_and_their_phantoms_do_not(): void
    {
        $enum = "<?php\n\nnamespace App\\Bridge\\Support;\n\nenum Grade: string\n{\n    case High = 'high';\n}\n";

        foreach (['Widget::ID', 'Widget::$label', 'Grade::High', 'Grade::cases()', 'Widget::class'] as $real) {
            $this->assertAccepted([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                'app/Bridge/Support/Grade.php' => $enum,
                'CLAUDE.md' => "Reads `{$real}`.\n",
            ], "`{$real}` names a real member (or a language construct) and must pass");
        }

        foreach (['Widget::NOPE', 'Widget::$missing', 'Grade::Low'] as $phantom) {
            $this->assertMemberRejected([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                'app/Bridge/Support/Grade.php' => $enum,
                'CLAUDE.md' => "Reads `{$phantom}`.\n",
            ], 'CLAUDE.md:1', "`{$phantom}` names nothing and must fail");
        }
    }

    /**
     * The whole point of the extension: the append-only log stops being exempt outright.
     * Paired with the path leg, which stays exempt there ON PURPOSE — its prose is
     * time-stamped, so the same dangling path is a finding in a current-state doc and not in
     * the log.
     */
    public function test_the_decision_log_is_covered_for_members_and_still_exempt_for_paths(): void
    {
        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "**Consequences:** `Widget::brand()` stamps the card.\n",
        ], 'CLAUDE_DECISIONS.md:1', 'a phantom member in the decision log must now fail — this is the coverage the card asked for');

        $this->assertAccepted([
            'CLAUDE_DECISIONS.md' => "Anticipated files: `app/Bridge/Support/Gone.php`.\n",
        ], 'the path leg is deliberately not applied to the frozen log');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE.md' => "Named in `app/Bridge/Support/Gone.php` and `Widget::brand()`.\n",
        ], 'CLAUDE.md:1', 'the witness: the same tokens in a current-state doc are both examined');
    }

    /**
     * The discharge a frozen entry can actually use: an annotation appended beside the original
     * sentence, never an edit to it. The annotation carries the citation as well as the marker,
     * which is what makes it reach back past its own sentence — the scope is
     * `annotationCovers()`'s, shared with the file/FQCN leg and with the rejected-alternative
     * hatch (card#7127), and `DocRefMarkerScopeTest` is where that scope is pinned in both
     * directions.
     */
    public function test_a_removed_marker_discharges_a_frozen_citation_and_its_absence_does_not(): void
    {
        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "`Widget::brand()` stamped the card. **[card#6025:** there is no `Widget::brand()` in the tree today.**]**\n",
        ], 'an annotation carrying a removed-marker discharges the citation');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "`Widget::brand()` stamped the card. **[card#6025:** this entry is historical.**]**\n",
        ], 'CLAUDE_DECISIONS.md:1', 'the witness: an annotation with no marker phrase discharges nothing');
    }

    /**
     * A rejected alternative is absent BY DECISION. The exemption is SENTENCE-scoped, and the
     * second vector is what proves that: one decision-log line carries the rejected
     * alternative and the built consequence side by side, and only the alternative is exempt.
     */
    public function test_a_rejected_alternative_is_exempt_and_a_sibling_citation_on_the_same_line_is_not(): void
    {
        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "**Alternatives:** a per-target flag (`Widget::brand()`) — rejected: the call sites must remember to set it.\n",
        ], 'an alternative the entry says it rejected names a construct that must not exist');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "**Alternatives:** a per-target flag (`Widget::brand()`) — rejected: the call sites must remember to set it. **Consequences:** `Widget::emboss()` stamps the card.\n",
        ], 'CLAUDE_DECISIONS.md:1', 'the witness: the built consequence two clauses away is still examined');
    }

    /**
     * The three-valued answer, both ways. An inherited member is real; an ancestry that leaves
     * `app/` is a question this scan cannot answer and must not convict on.
     */
    public function test_inheritance_resolves_within_app_and_an_ancestry_that_leaves_it_is_unverifiable(): void
    {
        $parent = "<?php\n\nnamespace App\\Bridge\\Support;\n\nabstract class Base\n{\n    protected function inherited(): void {}\n}\n";
        $child = "<?php\n\nnamespace App\\Bridge\\Support;\n\nfinal class Child extends Base\n{\n}\n";
        $foreign = "<?php\n\nnamespace App\\Bridge\\Support;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nfinal class Row extends Model\n{\n}\n";

        $this->assertAccepted([
            'app/Bridge/Support/Base.php' => $parent,
            'app/Bridge/Support/Child.php' => $child,
            'CLAUDE.md' => "Calls `Child::inherited()`.\n",
        ], 'a member declared by an in-app parent is declared');

        $this->assertMemberRejected([
            'app/Bridge/Support/Base.php' => $parent,
            'app/Bridge/Support/Child.php' => $child,
            'CLAUDE.md' => "Calls `Child::absent()`.\n",
        ], 'CLAUDE.md:1', 'the witness: with the same ancestry fully readable, an absent member IS convictable');

        $this->assertAccepted([
            'app/Bridge/Support/Row.php' => $foreign,
            'CLAUDE.md' => "Calls `Row::firstOrFail()`.\n",
        ], 'a class extending a framework base inherits members this scan cannot see — reporting it would be a false finding');
    }

    /**
     * A basename carried by two files answers for neither. Guessing between them would invent
     * the finding; the paired vector is the same citation with one candidate removed.
     */
    public function test_an_ambiguous_bare_class_name_is_skipped_and_a_unique_one_is_not(): void
    {
        $twin = "<?php\n\nnamespace App\\Bridge\\Other;\n\nfinal class Widget\n{\n    public function brand(): void {}\n}\n";

        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'app/Bridge/Other/Widget.php' => $twin,
            'CLAUDE.md' => "Calls `Widget::brand()`.\n",
        ], 'two files carry the basename, so the citation names neither of them in particular');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE.md' => "Calls `Widget::brand()`.\n",
        ], 'CLAUDE.md:1', 'the witness: with one candidate the same citation is attributable and fails');
    }

    /**
     * Scope bound, stated as a test rather than a comment: a citation whose class lives outside
     * `app/` is not examined at all. The prose here cites test classes, external product
     * vocabulary and cross-repo constructs in this same shape.
     *
     * The twin is the vector this file shipped without, and it was the only acceptance here with
     * no paired rejection: it survived TOTAL REMOVAL of the member leg, so it measured nothing
     * but the harness. Moving the same class under `app/` is the one-variable change that turns
     * the citation from unanswerable into a finding.
     */
    public function test_a_class_outside_app_is_not_examined_and_the_same_class_inside_it_is(): void
    {
        $outside = "<?php\n\nnamespace Tests\\Support;\n\nfinal class Widget\n{\n}\n";

        $this->assertAccepted([
            'tests/Support/Widget.php' => $outside,
            'CLAUDE_TESTING.md' => "Calls `Widget::brand()`.\n",
        ], 'a test-tree class is outside the scanned population, so its members are not answered for');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_TESTING.md' => "Calls `Widget::brand()`.\n",
        ], 'CLAUDE_TESTING.md:1', 'the witness: the same citation over a class under `app/` IS answered for');
    }

    /**
     * The MEMBER leg reads every source rules 2 and 3 walk, not the six current-state docs the
     * path leg reads. Six of the eight false claims card#6025 fixed were written outside that
     * list — in `README.md`, in `docs/`, and in `app/` comments — so a leg bounded to it would
     * have caught two.
     */
    public function test_the_member_leg_reads_the_whole_source_surface(): void
    {
        $note = "<?php\n\nnamespace App\\Bridge\\Support;\n\n/** Stamped by `Widget::%s`. */\nfinal class Note\n{\n}\n";

        foreach (['README.md', 'docs/writeback.md', 'app/Bridge/Support/Note.php', 'tests/Support/Note.php'] as $surface) {
            $real = str_ends_with($surface, '.php')
                ? sprintf($note, 'stamp()')
                : "Stamped by `Widget::stamp()`.\n";
            $phantom = str_ends_with($surface, '.php')
                ? sprintf($note, 'brand()')
                : "Stamped by `Widget::brand()`.\n";

            $this->assertAccepted([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                $surface => $real,
            ], "a real citation in {$surface} must pass");

            $this->assertMemberRejected([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                $surface => $phantom,
            ], $surface.':', "the witness: a phantom in {$surface} is examined there too");
        }
    }

    /**
     * An unqualified ancestor is resolved through the file's IMPORT block, never by basename.
     * The two trees differ in ONE line — which namespace the import names — and that line is
     * the whole question: `extends Command` under `use Illuminate\Console\Command;` is a class
     * this scan cannot see into, and answering it with whatever `app/` file shares the basename
     * convicts where the three-valued design has to say nothing.
     */
    public function test_an_ancestor_is_resolved_through_the_import_block_and_not_by_basename(): void
    {
        $collision = "<?php\n\nnamespace App\\Bridge\\Support;\n\nfinal class Command\n{\n    public function label(): string\n    {\n        return 'x';\n    }\n}\n";
        $subject = "<?php\n\nnamespace App\\Console;\n\nuse %s;\n\nfinal class PruneCommand extends Command\n{\n}\n";

        $this->assertAccepted([
            'app/Bridge/Support/Command.php' => $collision,
            'app/Console/PruneCommand.php' => sprintf($subject, 'Illuminate\\Console\\Command'),
            'CLAUDE.md' => "The cutoff comes from `PruneCommand::argument()`.\n",
        ], 'the base is a framework class, so the member is unverifiable — the colliding basename must not answer for it');

        $this->assertMemberRejected([
            'app/Bridge/Support/Command.php' => $collision,
            'app/Console/PruneCommand.php' => sprintf($subject, 'App\\Bridge\\Support\\Command'),
            'CLAUDE.md' => "The cutoff comes from `PruneCommand::argument()`.\n",
        ], 'CLAUDE.md:1', 'the witness: with the import naming the app class, the same ancestry IS readable and declares no argument');
    }

    /**
     * A bare class name is resolved only when ONE construct in the tree carries it. A second
     * file under `app/` is one way to be ambiguous; a non-`App\` class the tree imports under
     * that name is the other, and it is the one a basename scan cannot see.
     */
    public function test_a_name_the_tree_imports_from_outside_app_is_ambiguous(): void
    {
        $importer = "<?php\n\nnamespace App\\Console;\n\nuse Illuminate\\Console\\Command;\n\nfinal class Runner\n{\n    public function make(): Command\n    {\n        return new Command;\n    }\n}\n";
        $collision = "<?php\n\nnamespace App\\Bridge\\Support;\n\nfinal class Command\n{\n}\n";

        $this->assertAccepted([
            'app/Bridge/Support/Command.php' => $collision,
            'app/Console/Runner.php' => $importer,
            'CLAUDE.md' => "Reads `Command::argument()`.\n",
        ], 'the tree knows two `Command`s, so a bare citation of one names neither in particular');

        $this->assertMemberRejected([
            'app/Bridge/Support/Command.php' => $collision,
            'CLAUDE.md' => "Reads `Command::argument()`.\n",
        ], 'CLAUDE.md:1', 'the witness: with the external import gone the name is unique and the citation is attributable');
    }

    /**
     * A citation that carries ARGUMENTS names the same member as its bare spelling. The
     * anchored form dropped it before the census counted it — silently, which is the half of
     * the trade that made the exclusion invisible.
     */
    public function test_a_citation_carrying_arguments_is_read_as_the_member_it_names(): void
    {
        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE.md' => "Call `Widget::stamp(string \$label, ?int \$at = null)` to stamp.\n",
        ], 'a real member cited with its signature must pass');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE.md' => "Call `Widget::brand(string \$label, ?int \$at = null)` to stamp.\n",
        ], 'CLAUDE.md:1', 'the witness: the same spelling over a phantom is a finding, not a token the shape drops');
    }

    /**
     * `enum` is a WORD as well as a keyword. Matching it as a word let any class whose prose
     * mentions one answer for `cases()`/`from()`/`tryFrom()` — the synthesised-method exemption
     * granted to a class that synthesises nothing.
     */
    public function test_the_enum_exemption_needs_a_declaration_not_the_word(): void
    {
        $prose = "<?php\n\nnamespace App\\Bridge\\Support;\n\n/** Mentions an enum Severity in passing. */\nfinal class Widget\n{\n}\n";

        $this->assertAccepted([
            'app/Bridge/Support/Grade.php' => "<?php\n\nnamespace App\\Bridge\\Support;\n\nenum Grade: string\n{\n    case High = 'high';\n}\n",
            'CLAUDE.md' => "Reads `Grade::cases()`.\n",
        ], 'a real enum declares nothing but PHP synthesises the three, so they must pass');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => $prose,
            'CLAUDE.md' => "Reads `Widget::cases()`.\n",
        ], 'CLAUDE.md:1', 'the witness: a class that merely says the word `enum` synthesises nothing');
    }

    /**
     * The harness parks the script under test OUTSIDE every scanned root, and this is what that
     * buys. The script's own comments cite real constructs by name; parked in `bin/`, it was read
     * by the very leg it implements, so a fixture declaring a class of that name turned a comment
     * in the SCRIPT into a finding against the FIXTURE. It fired once, on a comment, while
     * something else was being debugged — the shape that gets a gate called flaky.
     */
    public function test_a_fixture_may_declare_a_class_the_scripts_own_comments_cite(): void
    {
        $cited = "<?php\n\nnamespace App\\Bridge\\Support;\n\nfinal class DlTokenGrammar\n{\n}\n";

        $this->assertAccepted([
            'app/Bridge/Support/DlTokenGrammar.php' => $cited,
            'CLAUDE.md' => "Nothing here cites it.\n",
        ], 'the script cites `DlTokenGrammar::sole()` in its own docblock, and it is not part of the tree it examines');

        $this->assertMemberRejected([
            'app/Bridge/Support/DlTokenGrammar.php' => $cited,
            'CLAUDE.md' => "The sole token comes from `DlTokenGrammar::sole()`.\n",
        ], 'CLAUDE.md:1', 'the witness: the same citation made by the FIXTURE is examined, so the acceptance is not inertness');
    }

    /**
     * The marker vocabulary reads the two speech acts a CHANGELOG entry makes by construction.
     * `\(removed\b` matched only a parenthesised "(removed in vX)", so a release note saying a
     * construct was removed did not discharge and a rename had no vocabulary at all — a gate
     * reddening on correct prose, which is how a gate gets switched off. The last two vectors are
     * the teeth: widening the words does not weaken the rule, it only names more of the ways an
     * author says the referent is gone.
     *
     * Each annotation REPEATS the citation, because the marker is read at the scope of the
     * sentence it is written in (card#7127). "removed in v0.60" appended to a forty-sentence
     * frozen entry names none of its citations and now discharges none of them — the second
     * rejection vector below is that case, and it is the one this file shipped as an acceptance.
     */
    public function test_a_removed_or_renamed_marker_discharges_a_citation_and_its_absence_does_not(): void
    {
        foreach ([
            'removed' => '**[card#6025:** `Widget::brand()` removed in v0.60.**]**',
            'renamed' => '**[card#6025:** `Widget::brand()` renamed `stamp()`.**]**',
        ] as $word => $annotation) {
            $this->assertAccepted([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                'CLAUDE_DECISIONS.md' => "`Widget::brand()` stamped the card. {$annotation}\n",
            ], "an annotation saying the referent was {$word} discharges the citation");
        }

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "`Widget::brand()` stamped the card. **[card#6025:** this entry is historical.**]**\n",
        ], 'CLAUDE_DECISIONS.md:1', 'the witness: a line carrying no marker word at all still reds');

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'CLAUDE_DECISIONS.md' => "`Widget::brand()` stamped the card. **[card#6025:** removed in v0.60.**]**\n",
        ], 'CLAUDE_DECISIONS.md:1', 'the second witness: a marker in an annotation that names no citation discharges none');
    }

    /**
     * The bound on that widening, and the sharpest one available: both words are matched as
     * WORDS. An identifier that merely starts with one — the `removedAt` column, a `renamedTo`
     * payload key — is prose about a field, not a statement that the cited member is gone, and a
     * substring match would have handed every line mentioning either a silent exemption.
     */
    public function test_the_marker_words_are_not_prefix_matched(): void
    {
        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'docs/writeback.md' => "`Widget::brand()` writes removedAt and renamedTo.\n",
        ], 'docs/writeback.md:1', 'identifiers that begin with a marker word discharge nothing');

        $this->assertAccepted([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'docs/writeback.md' => "`Widget::brand()` was removed; it wrote removedAt and renamedTo.\n",
        ], 'the witness: the same line with the word used as the statement it is does discharge');
    }

    /**
     * The declaration search is scoped to the CITED class's body. A whole-file scan lets a
     * sibling class in the same file vouch for it — the same defect as the ancestry basename
     * guess, one scope down.
     */
    public function test_a_sibling_class_in_the_same_file_does_not_vouch_for_the_cited_one(): void
    {
        $pair = "<?php\n\nnamespace App\\Bridge\\Support;\n\nfinal class Two\n{\n%s}\n\nfinal class Helper\n{\n    public function beta(): void {}\n}\n";

        $this->assertAccepted([
            'app/Bridge/Support/Two.php' => sprintf($pair, "    public function beta(): void {}\n"),
            'CLAUDE.md' => "Calls `Two::beta()`.\n",
        ], 'the cited class declares the member itself');

        $this->assertMemberRejected([
            'app/Bridge/Support/Two.php' => sprintf($pair, ''),
            'CLAUDE.md' => "Calls `Two::beta()`.\n",
        ], 'CLAUDE.md:1', 'the witness: only the sibling declares it, and a sibling is not an ancestor');
    }

    /**
     * THE `{@see …}` FORM (card#7330). The harvest was backtick-only, so the citation form this
     * tree's docblocks overwhelmingly use was not a token either leg read — and the run reported
     * an "examined" count and a clean verdict over a population it could not see.
     *
     * PAIRED IN BOTH DIRECTIONS, and the acceptance is NOT the witness here: a `{@see …}` phantom
     * passed before this change too, because it was never harvested. What discriminates is the
     * rejection, plus {@see test_a_see_tag_citation_is_counted_and_not_merely_unreported()} — being
     * unreported and being unread produce the same exit code, and only the census tells them apart.
     */
    public function test_a_see_tag_citation_is_read_in_the_forms_this_tree_writes(): void
    {
        $doc = "<?php\n\nnamespace App\\Bridge\\Support;\n\n/**\n * Stamped by %s.\n */\nfinal class Note\n{\n}\n";

        foreach (['{@see Widget::stamp()}', '{@see Widget::ID}', '{@see Widget::$label}'] as $real) {
            $this->assertAccepted([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                'app/Bridge/Support/Note.php' => sprintf($doc, $real),
            ], "{$real} names a real member and must pass");
        }

        foreach (['{@see Widget::brand()}', '{@see Widget::$missing}', '{@see Widget::brand() the label}'] as $phantom) {
            $this->assertMemberRejected([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                'app/Bridge/Support/Note.php' => sprintf($doc, $phantom),
            ], 'app/Bridge/Support/Note.php:6', "{$phantom} names nothing and must fail");
        }

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'app/Bridge/Support/Note.php' => "<?php\n\nnamespace App\\Bridge\\Support;\n\n/**\n * @see Widget::brand()\n */\nfinal class Note\n{\n}\n",
        ], 'app/Bridge/Support/Note.php:6', 'the standalone `@see` tag line is the same citation and must fail too');
    }

    /**
     * THE VECTOR THAT SEPARATES "not reported" FROM "never read" — the whole content of card#7330.
     * A phantom in a form the harvester cannot see produces exit 0, which is byte-identical to a
     * tree that has no phantom; the only shipped instrument that tells them apart is the census
     * line every run prints. So this asserts the COUNT, over a tree whose entire member population
     * is the one `{@see …}` citation.
     */
    public function test_a_see_tag_citation_is_counted_and_not_merely_unreported(): void
    {
        [$rc, $out] = $this->runGate([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'app/Bridge/Support/Note.php' => "<?php\n\nnamespace App\\Bridge\\Support;\n\n/** Stamped by {@see Widget::stamp()}. */\nfinal class Note\n{\n}\n",
        ]);

        $this->assertSame(0, $rc, "a real member cited as `{@see …}` must pass:\n{$out}");
        $this->assertStringContainsString('1 examined (1 resolved, 0 reported)', $out,
            "the citation must be COUNTED, not skipped into silence — an uncounted phantom exits 0 exactly like a clean tree:\n{$out}");

        [$rc, $out] = $this->runGate([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'app/Bridge/Support/Note.php' => "<?php\n\nnamespace App\\Bridge\\Support;\n\n/** Stamped by {@see Widget::brand()}. */\nfinal class Note\n{\n}\n",
        ]);

        $this->assertSame(1, $rc, "the witness: the same shape naming a phantom must red:\n{$out}");
        $this->assertStringContainsString('1 examined (0 resolved, 1 reported)', $out,
            "the phantom must be counted as REPORTED, not merely absent from the resolved bucket:\n{$out}");
    }

    /**
     * The discharge is decided by the same harvest as the citation, so an annotation may repeat
     * the citation in EITHER form — a rule that recognised only backticks would leave a frozen
     * `{@see …}` citation with no discharge available at all.
     */
    public function test_a_removed_marker_discharges_a_see_tag_citation_in_either_spelling(): void
    {
        $doc = "<?php\n\nnamespace App\\Bridge\\Support;\n\n/** Stamped by {@see Widget::brand()}. %s */\nfinal class Note\n{\n}\n";

        foreach (['{@see Widget::brand()} was removed.', '`Widget::brand()` was removed.'] as $annotation) {
            $this->assertAccepted([
                'app/Bridge/Support/Widget.php' => self::SUBJECT,
                'app/Bridge/Support/Note.php' => sprintf($doc, $annotation),
            ], "an annotation repeating the citation as `{$annotation}` discharges it");
        }

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'app/Bridge/Support/Note.php' => sprintf($doc, '{@see Widget::stamp()} was removed.'),
        ], 'app/Bridge/Support/Note.php:5', 'the witness: a marker naming a DIFFERENT citation discharges nothing');
    }

    /**
     * THE BOUND, stated as a test because an unstated one is how the backtick-only scope survived
     * (card#7330). Harvesting the `{@see …}` form changed WHERE a token is found, not what a token
     * MEANS: a payload is read exactly when its backticked twin would be. So the pseudo-classes
     * stay unread — `self::` names no file, and in markdown prose there is no enclosing class to
     * resolve it against — and so does a bare member name, which this repo writes inside `{@see …}`
     * for the enclosing class's own members. Both are gaps, not protection — no size is quoted for
     * either here, because a figure in a comment is a quoted authority no later pass recomputes;
     * the gate's own docblock carries the derivations.
     *
     * ⚠ NOT CONVICTED IS ONLY HALF OF IT, and this test asserts the half that was never in doubt.
     * Both forms were also absent from the CENSUS until card#7473, so the run's own accounting
     * excluded exactly what it could not answer while reading as complete — the direct test of
     * that is {@see test_qualifying_a_class_less_citation_moves_buckets_and_leaves_the_total_fixed()},
     * and an acceptance here says nothing about it either way.
     */
    public function test_a_see_tag_naming_a_pseudo_class_or_a_bare_member_is_a_disclosed_gap(): void
    {
        $doc = "<?php\n\nnamespace App\\Bridge\\Support;\n\n/** Stamped by %s. */\nfinal class Widget\n{\n    public function stamp(): void {}\n}\n";

        foreach (['{@see self::brand()}', '{@see static::brand()}', '{@see brand()}'] as $unread) {
            $this->assertAccepted([
                'app/Bridge/Support/Widget.php' => sprintf($doc, $unread),
            ], "{$unread} is outside the token set and must not be convicted on");
        }

        $this->assertMemberRejected([
            'app/Bridge/Support/Widget.php' => sprintf($doc, '{@see Widget::brand()}'),
        ], 'app/Bridge/Support/Widget.php:5', 'the witness: the SAME phantom member, qualified by its class, IS examined');
    }

    /**
     * THE DIRECT TEST OF card#7473, and it is a property rather than a figure: qualifying a
     * class-less citation into its `Class::member` spelling MOVES it between census buckets and
     * leaves the TOTAL fixed.
     *
     * WHY THAT PROPERTY AND NOT A COUNT. A citation the harvest drops before any bucket leaves an
     * accounting that still balances — the buckets summed to the population all along, because
     * the population was defined as what the buckets could hold. The measurement that convicts it
     * needs no instrument the run does not already ship: on the tree at filing, qualifying ONE
     * `{@see toArray}` moved resolved 959 -> 960 AND the total 1516 -> 1517, and a citation that
     * only enters the total once it becomes CHECKABLE was never in it. So the assertion here is
     * the INVARIANCE, over trees whose entire member population is the one citation under test.
     *
     * NOTHING IS RESOLVED BY THIS — the class-less forms are still unexamined and still cannot be
     * convicted ({@see test_a_see_tag_naming_a_pseudo_class_or_a_bare_member_is_a_disclosed_gap()}
     * is the twin that pins that half). What changed is only whether the run admits them.
     */
    public function test_qualifying_a_class_less_citation_moves_buckets_and_leaves_the_total_fixed(): void
    {
        $doc = "<?php\n\nnamespace App\\Bridge\\Support;\n\n/** Stamped by %s. */\nfinal class Note\n{\n}\n";
        $tree = fn (string $citation): array => [
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'app/Bridge/Support/Note.php' => sprintf($doc, $citation),
        ];

        $this->assertSame(
            ['total' => 1, 'resolved' => 1, 'classless' => 0, 'pseudo' => 0],
            $this->censusOf($tree('{@see Widget::stamp()}')),
            'the reference point: qualified, the citation is one examined member and the total is one'
        );

        foreach (['{@see stamp}', '{@see stamp()}', '{@see $label}'] as $bare) {
            $this->assertSame(
                ['total' => 1, 'resolved' => 0, 'classless' => 1, 'pseudo' => 0],
                $this->censusOf($tree($bare)),
                "{$bare} names a member with no class: it must be DISCLOSED as unexamined, and the total must not move when it is qualified"
            );
        }

        foreach (['{@see self::stamp()}', '{@see static::stamp}', '{@see parent::stamp()}'] as $pseudo) {
            $this->assertSame(
                ['total' => 1, 'resolved' => 0, 'classless' => 0, 'pseudo' => 1],
                $this->censusOf($tree($pseudo)),
                "{$pseudo} names no file either, and qualifying it must move it between buckets rather than into the total"
            );
        }
    }

    /**
     * THE BOUND ON THE TWO NEW BUCKETS, stated as a test because card#7473's whole finding is that
     * an unstated one still reads as complete.
     *
     * THE PREDICATE IS A LOWER-CASE INITIAL, which is the only thing that separates a member name
     * from a class name when there is no `::` to key on. So an UPPER-case class-less member — a
     * constant, an enum case — is in NEITHER bucket, and qualifying one still moves the total.
     * That is the remaining shape, and it is disclosed here rather than assumed away.
     *
     * ONLY A REFERENCE TAG IS READ. A backticked bare `stamp()` in prose stays out for the reason
     * the rule docblock's bare-`func()` paragraph gives — most of them here are other people's
     * vocabulary or historical narration, not claims about this tree — where a payload inside
     * `{@see …}` is a machine-readable claim by construction. Counting the backticked form would
     * put every mention of `array_map()` in the disclosure.
     *
     * `::class` IS NOT A MEMBER in either spelling, which is the exclusion the qualified leg
     * already makes; a bucket that swallowed it would be counting a language construct.
     */
    public function test_the_new_buckets_read_only_a_reference_tag_and_only_a_lower_case_member(): void
    {
        $doc = "<?php\n\nnamespace App\\Bridge\\Support;\n\n/** Stamped by %s. */\nfinal class Note\n{\n}\n";
        $tree = fn (string $citation): array => [
            'app/Bridge/Support/Widget.php' => self::SUBJECT,
            'app/Bridge/Support/Note.php' => sprintf($doc, $citation),
        ];
        $none = ['total' => 0, 'resolved' => 0, 'classless' => 0, 'pseudo' => 0];

        foreach (['`stamp()`', '`self::stamp()`', '{@see ID}', '{@see Widget}', '{@see self::class}'] as $unread) {
            $this->assertSame($none, $this->censusOf($tree($unread)),
                "{$unread} is outside the population these buckets disclose and must not be counted into it");
        }

        $this->assertSame(['total' => 1, 'resolved' => 0, 'classless' => 1, 'pseudo' => 0],
            $this->censusOf($tree('{@see stamp}')),
            'the witness: the same tree with the one form the buckets DO read counts exactly one — so the zeroes above are a predicate, not an inert harness');
    }

    /**
     * The census the run already prints on every line, read back as numbers.
     *
     * IT PARSES THE WHOLE DISCLOSURE LINE, not just the field under test: the format is the
     * gate's contract with a reader auditing what it declined to answer, so a bucket dropped from
     * the sentence must red here rather than be silently skipped by a lenient pattern. The TOTAL
     * is `examined + NOT examined` — derived from the shipped line rather than from `--census`,
     * which is a flag no CI invocation passes.
     *
     * @param  array<string, string>  $files
     * @return array{total: int, resolved: int, classless: int, pseudo: int}
     */
    private function censusOf(array $files): array
    {
        [$rc, $out] = $this->runGate($files);
        $this->assertSame(0, $rc, "the census vectors carry no phantom and must pass:\n{$out}");

        $line = '/member citations — (\d+) examined \((\d+) resolved, (\d+) reported\), (\d+) NOT examined: '
            .'(\d+) class not under app\/, (\d+) ambiguous class name, (\d+) unverifiable ancestry, '
            .'(\d+) in a removed-marker sentence, (\d+) a rejected alternative, '
            .'(\d+) class-less member citations, (\d+) naming a pseudo-class;/';

        $this->assertSame(1, preg_match($line, $out, $m),
            "every run must disclose every bucket by name — a missing one is the defect card#7473 fixed:\n{$out}");

        return [
            'total' => (int) $m[1] + (int) $m[4],
            'resolved' => (int) $m[2],
            'classless' => (int) $m[10],
            'pseudo' => (int) $m[11],
        ];
    }
}
