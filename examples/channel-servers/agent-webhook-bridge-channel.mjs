#!/usr/bin/env node
// Reference channel MCP server for the agent-webhook-bridge's channel_push handler.
//
// Unix domain socket is the recommended default for the same-host case; HTTP is
// available behind BRIDGE_CHANNEL_TRANSPORT=http for SSH-tunneled multi-host setups.
//
// Topology:
//
//   agent-webhook-bridge (Laravel)
//        │  ChannelPushHandler (app/Bridge/Handlers/ChannelPushHandler.php) runs
//        │  synchronously in the webhook request and POSTs over UDS (default)
//        │  or http://127.0.0.1:8788
//        ▼
//   THIS SERVER (Node MCP child of Claude Code, spawned over stdio)
//        │  forwards as notifications/claude/channel
//        ▼
//   Claude Code surfaces in conversation as
//        <channel source="agent-webhook-bridge" kind="..." target_id="...">
//          {"intent": {...}}
//        </channel>
//
// Per the Claude Code channels reference
// (https://code.claude.com/docs/en/channels-reference), a channel server:
//   1. declares `capabilities.experimental['claude/channel'] = {}`
//   2. emits `notifications/claude/channel` events with
//      `{ content: string, meta?: Record<string, string> }`
//   3. connects over stdio (Claude Code spawns the process)
//
// This server is INTENTIONALLY MINIMAL. Treat it as a worked example to copy
// into your own deployment, not a production daemon. The bridge ships the
// handler; the server lifecycle is the operator's concern.

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  ListToolsRequestSchema,
  CallToolRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
// Helpers split into sibling modules so they can be tested directly (this file
// self-executes on import) and shared with the launch-time updater: channel-lib.mjs
// holds the relay contract and the bridge transport, entry.mjs the channel's socket and
// `.FAILED` marker paths (its own refusals write the same marker). Plain ESM, no build
// step; consumers copy the whole directory, and the client pack carries it whole.
import {
  deriveMeta,
  relayBridgeResponse,
  resolveToolsToken,
  sshRoundTrip,
  httpRoundTrip,
  launchIdentity,
  clientUpdateInstruction,
  errorDetail,
  readClientVersion,
} from './channel-lib.mjs';
import { channelSocketPath, failureMarkerPath } from './entry.mjs';

const SERVER_NAME = process.env.BRIDGE_CHANNEL_NAME || 'agent-webhook-bridge';
const TRANSPORT = (process.env.BRIDGE_CHANNEL_TRANSPORT || 'unix').toLowerCase();
const SHARED_TOKEN = process.env.BRIDGE_CHANNEL_TOKEN || '';

// Two-way board tools (DL-217), advertised on a TRI-STATE. When on, this server
// ALSO advertises the `tools` MCP capability and PROXIES tools/call to the bridge's
// loopback POST /agent-tools/call with the per-agent bearer. It stays a DUMB PIPE:
// no board logic, no kanban token, no retry. The advertise decision:
//   BRIDGE_CHANNEL_TOOLS === '1'  → force ON.
//   BRIDGE_CHANNEL_TOOLS === '0' or ''  → OFF (explicit opt-out; an empty string
//                                          does NOT enable).
//   BRIDGE_CHANNEL_TOOLS unset  → advertise IFF BRIDGE_TOOLS_ENDPOINT is set AND a
//                                 bearer resolves (wire the one endpoint line and
//                                 the tools come on for free; a bare channel agent
//                                 with no tools wiring advertises nothing).
// The transport is EXCLUSIVE (single-valued per seat, v1): either the HTTP loopback
// endpoint OR the SSH-forced-command target — never both (a startup refuse enforces it).
//
// HTTP transport (BRIDGE_TOOLS_ENDPOINT + a bearer):
//   BRIDGE_TOOLS_ENDPOINT    — the bridge's loopback URL for the call ingress,
//                              e.g. http://127.0.0.1:8787/agent-tools/call
//   BRIDGE_TOOLS_TOKEN       — the bearer value, OR
//   BRIDGE_TOOLS_TOKEN_FILE  — a path (chmod 600) to read it from, OR
//   (fallback) BRIDGE_CHANNEL_TOKEN — reused when neither explicit tools token is
//                              configured (the default-ON model: the impl↔PM shared
//                              channel token IS the bearer, no new credential).
//
// SSH-forced-command transport (card 4952 — no bearer, no forwarding, works on an
// AllowTcpForwarding-remote seat where the HTTP loopback tunnel cannot):
//   BRIDGE_TOOLS_SSH_TARGET  — user@host of the bridge box; the client passes NO
//                              command (sshd substitutes the pinned bridge:tools-call).
//   BRIDGE_TOOLS_SSH_KEY     — optional path to the identity key (-i).
//   BRIDGE_TOOLS_SSH_PORT    — optional ssh port (-p).
const CHANNEL_TOOLS_ENV = process.env.BRIDGE_CHANNEL_TOOLS;
const TOOLS_ENDPOINT = process.env.BRIDGE_TOOLS_ENDPOINT || '';
const TOOLS_SSH_TARGET = process.env.BRIDGE_TOOLS_SSH_TARGET || '';
const TOOLS_SSH_KEY = process.env.BRIDGE_TOOLS_SSH_KEY || '';
const TOOLS_SSH_PORT = process.env.BRIDGE_TOOLS_SSH_PORT || '';
// Overall client-side deadline for one ssh board-tools round-trip. `-o ConnectTimeout`
// (below) bounds only the TCP/handshake; this caps the WHOLE call so a host that
// connects then hangs — or a wedged forced command — cannot pin the tools/call
// indefinitely or leak the child. Mirrors the PHP probe posture
// (SystemSshProbeEnvironment::sshRoundTrip: ConnectTimeout=10 + a 30s process timeout).
const TOOLS_SSH_DEADLINE_MS = 60000;
// How much of a failing ssh leg's stderr is held for the tool result (card#7709).
// Above `scrubSnippet`'s own 500-char bound so a credential line that starts inside
// the capture is still whole when the scrub anchors on it; the relay is what decides
// how much of it a caller finally sees.
const SSH_STDERR_CAPTURE_LIMIT = 2000;

// This server's OWN package version, sent on every board-tools call as `client_version`
// (card#8974 / DL-364) — `readClientVersion` in channel-lib.mjs owns why it is read from the
// manifest and why it is fail-soft.
const CLIENT_VERSION = readClientVersion();

// The version announced in the MCP `initialize` handshake (serverInfo) is that same
// once-read value — never a literal of its own, which is free to drift from the manifest
// the bump guard maintains. Unlike `client_version`, serverInfo.version cannot be omitted, so an
// unreadable manifest announces this sentinel: plainly not a release, never a plausible
// stale number.
const HANDSHAKE_VERSION = CLIENT_VERSION ?? '0.0.0-unreadable-manifest';

// The launch this server belongs to, when entry.mjs started it (card#10568): sent on every
// board-tools call as `launch`, so the bridge knows which release this launch runs.
// Null — and the key omitted — for a server started any other way.
const LAUNCH = launchIdentity(process.env);

function shouldAdvertiseTools() {
  if (CHANNEL_TOOLS_ENV === '1') {
    return true;
  }
  if (CHANNEL_TOOLS_ENV === '0' || CHANNEL_TOOLS_ENV === '') {
    return false;
  }
  // Unset: observable-intent default. The ssh branch is BEARER-FREE (DR2-5) — an ssh
  // target alone enables it, with NO token term (an `&& token` here would leave an
  // ssh-only seat dark). The HTTP branch still needs the endpoint line AND a bearer.
  if (TOOLS_SSH_TARGET !== '') {
    return true;
  }
  return TOOLS_ENDPOINT !== '' && resolveToolsToken(process.env) !== '';
}

const TOOLS_ENABLED = shouldAdvertiseTools();

// clear_context is a LOCAL-EXEC self-management tool (card 5089), advertised on a gate
// that is ORTHOGONAL to the board tools above: it NEVER proxies to the bridge, so it has
// no endpoint/bearer/transport term. It can be advertised when the board tools are not,
// and vice versa. The helper it spawns clears THIS agent's own GNU screen window.
const CLEAR_AGENT_HELPER = 'clear-agent.sh';

// Idiomatic PATH resolution: search $PATH left-to-right for an executable `name`, exactly
// as a shell would, and return the first hit's resolved path (or null). No hardcoded dir.
function resolveOnPath(name) {
  const raw = process.env.PATH || '';
  if (!raw) {
    return null;
  }
  for (const dir of raw.split(path.delimiter)) {
    if (!dir) {
      continue;
    }
    const candidate = path.join(dir, name);
    try {
      fs.accessSync(candidate, fs.constants.X_OK);
      return candidate;
    } catch {
      // not in this dir, or present but not executable — keep searching
    }
  }
  return null;
}

// Advertise clear_context IFF this seat is inside a GNU screen session ($STY set) AND the
// clear-agent.sh helper is resolvable on PATH. Mirrors shouldAdvertiseTools()'s env-reading
// style but shares NONE of its terms — a bare-channel seat with tools off can still arm
// clear_context, and a fully board-tools-wired seat with no $STY/helper does not.
// ⚠ $STY IS GNU SCREEN ONLY — tmux sets $TMUX and is NOT covered by this gate; the
// refusal text says so, and README's own wording already did.
function shouldAdvertiseClearContext() {
  return Boolean(process.env.STY) && resolveOnPath(CLEAR_AGENT_HELPER) !== null;
}

const CLEAR_CONTEXT_ENABLED = shouldAdvertiseClearContext();

// The tools MCP capability + the tools/list and tools/call handlers come on when EITHER
// tool family is advertised — the two gates are independent.
const ADVERTISE_ANY_TOOL = TOOLS_ENABLED || CLEAR_CONTEXT_ENABLED;

// The tool surface, hard-coded to mirror the bridge contract (DL-217; the
// correction tool is DL-326). Kept
// here because tools/list must advertise a schema; the bridge remains the single
// authority on validation/scoping — this is the MCP surface, not board logic. If
// the bridge contract changes, update both (a reference example server, by design).
const TOOL_DEFINITIONS = [
  {
    name: 'board_my_cards',
    description:
      'Return YOUR OWN cards on the board (your product swimlane grouped by stage, ' +
      'plus any shared/coordination cards your bridge identity is scoped to). Read-only; ' +
      'the kanban token never leaves the bridge. Titles only by default — pass ' +
      'include_description when you need the SCOPE written on a card. EACH card list is ' +
      'CAPPED by default; every list carries a window block (total / returned / limit / ' +
      'truncated) and truncated: true means there is more behind it — narrow with stage, ' +
      'or raise limit deliberately. NEVER read a truncated list as the whole board. ' +
      'Each card carries assigned_user_id: the raw kanban user id holding it, or null ' +
      'when nobody does. That is how you tell a card another seat is already working ' +
      'from a free one WHEN THE COLUMN NEVER MOVED — the bridge resolves no name for ' +
      'it, so an id you do not recognise is somebody else. board_take_card is how you ' +
      'claim a free one — but ONLY in your own lanes: coordination cards appear in the ' +
      'coord_cards block of this same response, they are on a different board, and they ' +
      'are NOT takeable (the attempt is refused write-free and says so). ' +
      'Your lane read NEVER shows a card that is in another lane or in NO lane, so ' +
      '"none of my cards carry tag X" is not something the lane read can tell you: pass ' +
      'tag to read every card on your board carrying that tag, whatever lane it is in. ' +
      'A board fault ' +
      'that cannot clear (the bridge token revoked/rotated, or its scope too narrow ' +
      'to read) is REFUSED (422) naming the INSTALL fault — it is never an empty ' +
      'window and never a retryable upstream error, so do not retry it: tell your ' +
      'operator.',
    inputSchema: {
      type: 'object',
      properties: {
        include_description: {
          type: 'boolean',
          description:
            "Include each card's body (default false). Opt-in because a body is ~2 KB " +
            'and it is added to EVERY card the call returns, so it multiplies the ' +
            'response size by the number of cards — ask for it when starting work on a ' +
            'card, not when ' +
            'polling. A body longer than the bridge-configured per-card cap is cut and ' +
            'the card carries description_truncated: true; never read a truncated body ' +
            'as the whole scope.',
        },
        stage: {
          // ⛔ `anyOf`, NOT `type: ['integer','string']` — MEASURED, not preferred. A
          // strict JSON Schema validator REFUSES the array-valued form at COMPILE time
          // ("strict mode: use allowUnionTypes to allow union type keyword"), and a client
          // that cannot compile this schema drops board_my_cards entirely for every seat
          // that re-copies this directory. The `anyOf` form compiles under the same strict
          // validator and discriminates correctly (integer ok, string ok, boolean
          // rejected). Measured against ajv in strict mode, which is in this very tree.
          anyOf: [{ type: 'integer' }, { type: 'string' }],
          description:
            'Return only cards in ONE column of your product board. The NUMERIC stage id ' +
            'is the primary form (it is what each card reports under "stage" alongside ' +
            'the id the bridge groups by). A STRING is treated as a stage NAME, matched ' +
            'case-insensitively, and is REFUSED if it names no stage or more than one — ' +
            'the bridge never guesses which column you meant. Narrows the tag_cards read ' +
            'too. Does not apply to the coordination cards: those are on a different ' +
            'board, whose stage ids are unrelated to yours.',
        },
        limit: {
          type: 'integer',
          minimum: 1,
          description:
            'How many cards EACH list is cut to (the bridge default is deliberately ' +
            'small — a whole board of titles overflows a context window). Raise it only ' +
            'when you genuinely need a whole lane: the response grows in proportion. ' +
            'Prefer narrowing with stage. Read the window block to see whether a cut ' +
            'happened and how much is behind it.',
        },
        tag: {
          type: 'string',
          description:
            'ONE tag, matched exactly (for example lane:A). Adds a tag_cards block: every ' +
            'live card on YOUR board carrying it, in ANY lane or in none, each with its own ' +
            'swimlane_id (null means the card is in no lane). The block counts the cards in ' +
            'other lanes (other_swimlanes) and in no lane (no_swimlane); a count the bridge ' +
            'could not stand behind is null with a reason in its *_unmeasured key — never ' +
            'read a null as zero. Terminal columns are left out unless include_terminal is ' +
            'true. Refused when it contains " * % / \\, a control character or any non-ASCII ' +
            'character (a kanban before v0.46.0 cannot match those exactly). Omit it and the response is ' +
            'exactly the default.',
        },
        include_terminal: {
          type: 'boolean',
          description:
            'Keep cards in terminal columns in the tag_cards read (default false). Which ' +
            'columns those are is the BOARD\'s declaration - its is_terminal flagged stages, ' +
            'or its lane_type done columns when it flags none - and the response says which ' +
            'answered in tag_cards.terminal_basis. Refused without tag.',
        },
      },
      additionalProperties: false,
    },
  },
  {
    name: 'board_create_card',
    description:
      'Create a card in YOUR OWN swimlane (the swimlane is forced from your bridge ' +
      'identity — you cannot target another lane). The card is born untriaged and ' +
      'surfaces to the triage pass. Pass an idempotency_key to make retries safe. ' +
      'The returned board_id/swimlane_id are READ BACK from the card and can differ ' +
      'from the scope you are configured for, which is returned beside them as ' +
      'configured_board_id/configured_swimlane_id. placement_observed: false means ' +
      'the bridge could not read the placement — both ids are then null and the ' +
      'response claims none; the card still exists and card_id is still the answer. ' +
      'A board fault that cannot clear (the bridge token revoked/rotated, its scope ' +
      'too narrow, or a value kanban itself rejects) is REFUSED (422) with NO card ' +
      'created — not a retryable upstream error, so do not retry it.',
    inputSchema: {
      type: 'object',
      properties: {
        title: {
          type: 'string',
          description:
            "Card title (required, non-empty, at most 255 characters — kanban's own " +
            'limit). An over-long title is REFUSED (422) before any request is sent.',
        },
        description: { type: 'string', description: 'Card body (optional).' },
        tags: {
          type: 'array',
          items: { type: 'string' },
          description:
            'Optional caller tags. Reserved prefixes (created-by:, idem:, id:, type:), ' +
            'the retired owner tag (owner:) and the bare tag "triaged" are refused — ' +
            'claim a card with board_take_card instead — and each tag is capped at 64 ' +
            "characters (kanban's own limit).",
        },
        idempotency_key: {
          type: 'string',
          description:
            'Optional but recommended: [A-Za-z0-9.-]{1,64}, and shorter for your agent: ' +
            'the key is stored in the tag idem:<agent>:<key> and kanban caps a tag at ' +
            '64 characters, so the key may be at most 64 minus the length of ' +
            '"idem:<agent>:" for your agent name; a longer key is REFUSED (422) before ' +
            'any request is sent, naming your cap. Re-using it returns the ' +
            'same LIVE card instead of creating a duplicate. If that card was ARCHIVED ' +
            'the call is REFUSED (422) naming the card to unarchive — an archived card ' +
            'is a retire, so no replacement is created; pass a NEW key for new work.',
        },
      },
      required: ['title'],
      additionalProperties: false,
    },
  },
  {
    name: 'board_correct_card',
    description:
      'Correct a card that is YOURS — its name, description or tags — instead of ' +
      'minting a second card to say the first one is wrong. A card on your own ' +
      'board is yours when it carries your own bridge-stamped created-by: tag OR ' +
      'is assigned to your own kanban user (resolved from your bridge identity — ' +
      'no argument names a user); the result says which one authorized the write ' +
      '(authorized_by: minted | assigned). Anything else is REFUSED, never ' +
      'silently ignored. A PRESENT argument is a ' +
      'correction and an ABSENT one leaves that field alone, so tags: [] means ' +
      '"drop my tags" and an empty description clears the body. Column moves, ' +
      'correlation refs (dl/pr/issue), external ids, card type and block_reason ' +
      'are NOT correctable here — each is refused by name with the authority that ' +
      'owns it. A PINNED card also refuses a name correction: a non-empty ' +
      'block_reason or a no-automove tag is a human freezing the card, and the ' +
      "bridge's own automation is refused the same write on the same card. " +
      'Because the correction is ONE patch with no half-applied form, a call ' +
      'sending name BESIDE description or tags then writes NEITHER — send those ' +
      'on their own, or ask whoever pinned it to lift the hold. ' +
      'Your tag list replaces only YOUR OWN tags: because kanban replaces ' +
      'the tag list wholesale, the bridge re-sends every tag on the card that is ' +
      "somebody else's — the ones you may not supply (created-by:, idem:, id:, " +
      'type:, owner:, triaged) AND the holds anyone may set but nobody else may drop ' +
      "(no-automove, plus your install's own hold tags).",
    inputSchema: {
      type: 'object',
      properties: {
        card_id: {
          type: 'integer',
          description:
            'The id of the card to correct, as board_my_cards reports it. Must be ' +
            'an integer — a decorated string is refused, never coerced.',
        },
        name: {
          type: 'string',
          description:
            'Replacement title (non-empty, at most 255 characters — kanban\'s own ' +
            'limit). There is no clear form — omit it to leave the title alone.',
        },
        description: {
          type: 'string',
          description:
            'Replacement body. Present-and-empty CLEARS it; omit it to leave it alone.',
        },
        tags: {
          type: 'array',
          items: { type: 'string' },
          description:
            'Your replacement tag list (an empty list drops YOUR tags). The same ' +
            'reserved prefixes (created-by:, idem:, id:, type:, owner:) and the bare tag ' +
            '"triaged" are refused as at create, and each tag is capped at 64 ' +
            'characters (kanban\'s own limit). Tags that are not yours to drop are ' +
            'preserved: the reserved ones (a legacy owner: tag included) and any hold ' +
            'marker (no-automove).',
        },
      },
      required: ['card_id'],
      additionalProperties: false,
    },
  },
  {
    name: 'board_take_card',
    description:
      'CLAIM a card for yourself — write your own kanban USER ID into the board\'s ' +
      'assigned_user_id so another seat can see the work is taken even when the column ' +
      'has not moved. (It is a numeric id, never a name: nothing here resolves a seat ' +
      'name, which is why no user-naming argument exists.) Use it the moment you start ' +
      'on a card, not when you finish. ' +
      'START FORM: pass start: true when you BEGIN work on the card. In ONE write it ' +
      'moves the card to your board\'s In Progress column AND assigns it to you, then ' +
      'reads both back; the result says moved, assigned (whether THIS call wrote each), ' +
      'replaced (whom it took the card from, or null) and stage_id. A card already In ' +
      'Progress is assigned without a move. A card in any other column — Backlog when it ' +
      'is not a start column, In Review, a finished column — or a PINNED card it would ' +
      'have to move is REFUSED ' +
      '(422) and nothing is written; the refusal carries a reason code, and a take ' +
      'without start still claims the card where it is. ' +
      'NO argument names a user: the bridge works out which kanban user you ' +
      'are from the identity your call authenticated as, so there is NO argument for ' +
      'a user id and there never will be — you can claim a card for yourself and for ' +
      'nobody else. Sending assigned_user_id, assignee, user_id or any other ' +
      'user-naming argument is REFUSED and nothing is written. ' +
      'Scoped to cards on YOUR board in a lane you work (your own swimlane, or the ' +
      'shared one if your bridge is configured for it). You do NOT have to have filed ' +
      'the card: taking work somebody else queued for you is the point. ' +
      '⚠ NOT every card board_my_cards shows you — the coord_cards block of that ' +
      'response is a DIFFERENT board, addressed to you by tag rather than held in a ' +
      'lane, and those cards are not takeable here. The refusal names that as the likely ' +
      'cause when your bridge has a coordination leg. ' +
      'A card ALREADY HELD BY SOMEBODY ELSE (another kanban user as assignee, or — ' +
      'with no assignee — another seat\'s legacy owner: tag) is TAKEN OVER: it is ' +
      'reassigned to you, a card comment names whom it replaced, and the result says ' +
      'so (replaced, warning, takeover_confirmed, takeover_comment). Read the warning: ' +
      'that holder may still be working it, so talk to them. The exception is replacing ' +
      'the ASSIGNEE of a card in a FINISHED column (Done, Won\'t Do, Shipped to dev, ' +
      'Shipped to main) — that assignee is the record of who did the work, so it is ' +
      'REFUSED (422) and nothing is written, and so is replacing the assignee of a card ' +
      'whose column the bridge cannot show is unfinished. A card held only by a legacy ' +
      'owner: tag is taken wherever it sits (the tag stays on it). There is no override ' +
      'here; replacing a finished card\'s assignee is a decision for your operator. ' +
      'Re-taking a card you already hold SUCCEEDS and answers already_held: true, so it ' +
      'is safe to call again if you are unsure; it writes nothing, except that start: true ' +
      'on a held card still in a start column moves it to In Progress. ' +
      'A board fault that cannot clear (the bridge token revoked/rotated, or the ' +
      'writeback role unable to update tasks) is REFUSED (422) naming the INSTALL ' +
      'fault — do not retry it; tell your operator, quoting the message as-is.',
    inputSchema: {
      type: 'object',
      properties: {
        card_id: {
          type: 'integer',
          description:
            'The id of the card to claim, as board_my_cards reports it. Must be an ' +
            'integer — a decorated string is refused, never coerced.',
        },
        start: {
          type: 'boolean',
          description:
            'true to START the card: move it to In Progress and assign it to you in one ' +
            'write (see the tool description for which columns it starts from). Omit it, ' +
            'or pass false, for a plain claim that never moves the card. Must be a ' +
            'boolean — a string, number or null is refused, never coerced.',
        },
      },
      required: ['card_id'],
      additionalProperties: false,
    },
  },
  {
    name: 'board_comment_card',
    description:
      'APPEND a comment to a card on YOUR board — the safe way to add a note to a card. ' +
      'It never reads or replaces the card: board_correct_card\'s description REPLACES the ' +
      'whole body, so appending with it needs the card\'s current text first, and a card ' +
      'outside your board_my_cards window has none to give you. A comment is a new row under ' +
      'the card and cannot overwrite anything. ' +
      'Any LIVE card on your own board may be commented on — you do not need to have filed it, ' +
      'hold it, or work its lane. A card on another board (coord_cards included), an ARCHIVED ' +
      'card, or an id that is not on your board is REFUSED and nothing is written. ' +
      'The bridge writes "FROM: <your seat>" as the comment\'s FIRST line, from the identity ' +
      'your call authenticated as — no argument names the author, and every seat shares one ' +
      'kanban user, so that line is the attribution. ' +
      'APPEND-ONLY: there is no edit or delete. It takes card_id and content and nothing else. ' +
      'NOT idempotent: only a refusal (422) tells you nothing was written. Any other failure ' +
      '— a 502, a 500, a non-JSON answer, a failed ssh leg or a timeout — may have landed ' +
      'the comment, so re-sending it can post a duplicate. ' +
      'A board fault that cannot clear (the bridge token revoked/rotated, or the writeback ' +
      'role unable to create comments) is REFUSED (422) naming the INSTALL fault — do not ' +
      'retry it; tell your operator, quoting the message as-is.',
    inputSchema: {
      type: 'object',
      properties: {
        card_id: {
          type: 'integer',
          description:
            'The id of the card to comment on, as board_my_cards reports it. Must be an ' +
            'integer — a decorated string is refused, never coerced.',
        },
        content: {
          type: 'string',
          description:
            'The comment text (markdown). Trimmed; blank is refused. At most 65535 characters ' +
            'INCLUDING the bridge\'s "FROM: <your seat>" line and the blank line after it ' +
            '(kanban\'s own limit).',
        },
      },
      required: ['card_id', 'content'],
      additionalProperties: false,
    },
  },
  {
    name: 'board_get_cards',
    description:
      'Read cards you already know the ids of in ONE call, whatever lane, column or archive ' +
      'state they are in. EVERY id you send comes back exactly once, in the order ' +
      'you sent it, with a status: found (live on your board), archived (on your board, ' +
      'archived), other_board (the id is a card on a different board, the coordination board ' +
      'included; nothing of that card is returned), or not_found (no card has that id, or it ' +
      'is in kanban\'s trash). An id is never silently left out. found and archived entries ' +
      'carry the card under card, each with its swimlane_id (null means no lane) and its ' +
      'position. Card order within a column IS its priority order: sort by (position, id) ' +
      'ascending within a stage — the lowest position is the top card. Read-only. ' +
      'There is no window here, so no truncated flag: nothing is cut, because the request ' +
      'itself is bounded. Where the bridge cannot establish a status for an id it REFUSES the ' +
      'whole call (422) and says why; it never answers with a hole. A board fault that cannot ' +
      'clear (the bridge token revoked/rotated, or its scope too narrow to read) is REFUSED ' +
      '(422) naming the INSTALL fault — do not retry it; tell your operator.',
    inputSchema: {
      type: 'object',
      properties: {
        ids: {
          type: 'array',
          items: { type: 'integer', minimum: 1 },
          minItems: 1,
          maxItems: 52,
          description:
            'The card ids to read (at most 52, each once). Integers only — a decorated string ' +
            'is refused, never coerced. A repeated id is refused.',
        },
        fields: {
          type: 'array',
          items: {
            type: 'string',
            enum: ['id', 'name', 'stage', 'position', 'swimlane_id', 'tags', 'assigned_user_id', 'dl_number', 'pr_number', 'pr_url', 'source', 'updated_at', 'description'],
          },
          description:
            'Which card fields to return. Omit it for every field EXCEPT description. ' +
            'description is opt-in per call: name it here to get each card\'s body (with ' +
            'description_truncated: true when the bridge cut it — never read a cut body as ' +
            'the whole scope). An empty list returns statuses only.',
        },
      },
      required: ['ids'],
      additionalProperties: false,
    },
  },
  {
    name: 'board_search',
    description:
      'Search the cards on YOUR board by filter and get the MATCHES ONLY — no lane list, no column ' +
      'list, no grouping. Every filter is applied by the board itself, and the filters combine ' +
      '(AND). lane defaults to any: cards in every lane of your board, each with its swimlane_id ' +
      '(null means no lane). Results are the NEWEST matches first, cut to limit; window says ' +
      'total (how many matched), returned, truncated (true when more matched than were returned) ' +
      'and total_is_lower_bound (true only when a tags_any union could not be sized exactly — ' +
      'truncated is then true too). summary: true returns counts instead of cards: total and ' +
      'by_stage, plus by_tag for the tags you name in summary_tags. Read-only. Where the board ' +
      'cannot show it applied a filter, the call is REFUSED (422) rather than answered with a ' +
      'count of something else. A board fault that cannot clear (the bridge token revoked/rotated, ' +
      'or its scope too narrow to read) is REFUSED (422) naming the INSTALL fault — do not retry ' +
      'it; tell your operator.',
    inputSchema: {
      type: 'object',
      properties: {
        tags_all: {
          type: 'array',
          items: { type: 'string' },
          minItems: 1,
          description: 'Cards carrying EVERY one of these tags (each matched exactly).',
        },
        tags_any: {
          type: 'array',
          items: { type: 'string' },
          minItems: 1,
          description:
            'Cards carrying AT LEAST ONE of these tags (each matched exactly). Costs one board ' +
            'read per tag. Cannot be combined with summary when it names more than one tag.',
        },
        stage: {
          type: 'array',
          items: { anyOf: [{ type: 'integer' }, { type: 'string' }] },
          minItems: 1,
          description:
            'Cards in any of these columns — each a numeric stage id or a stage name (matched ' +
            'case-insensitively; a name matching two columns is refused).',
        },
        pr_number: {
          type: 'integer',
          minimum: 1,
          description:
            'Cards tracking this pull-request number (any repo — each card carries source and ' +
            'pr_url to tell them apart). Live cards only: refused with include_archived.',
        },
        name_contains: {
          type: 'string',
          description: 'Cards whose name contains this text (the board\'s own match; no double quote).',
        },
        updated_since: {
          type: 'string',
          description: 'Cards updated on or after this DATE, YYYY-MM-DD (the board compares dates, not times).',
        },
        include_archived: {
          type: 'boolean',
          description: 'Also search archived cards; each card then carries archived: true or false.',
        },
        lane: {
          type: 'string',
          enum: ['mine', 'any', 'none'],
          description: 'mine = your own swimlane, none = cards in no lane, any = every lane (default).',
        },
        summary: {
          type: 'boolean',
          description: 'Return counts (total, by_stage, and by_tag for summary_tags) and no cards.',
        },
        summary_tags: {
          type: 'array',
          items: { type: 'string' },
          minItems: 1,
          description: 'With summary: true, also count the matches carrying each of these tags.',
        },
        fields: {
          type: 'array',
          items: {
            type: 'string',
            enum: ['id', 'name', 'stage', 'position', 'swimlane_id', 'tags', 'assigned_user_id', 'dl_number', 'pr_number', 'pr_url', 'source', 'updated_at', 'description'],
          },
          minItems: 1,
          description:
            'Which card fields to return. Omit it for every field EXCEPT description. ' +
            'description is opt-in per call: name it here to get each card\'s body (with ' +
            'description_truncated: true when the bridge cut it — never read a cut body as ' +
            'the whole scope). Not with summary.',
        },
        limit: {
          type: 'integer',
          minimum: 1,
          maximum: 200,
          description:
            'How many cards to return, newest first (default 52, at most 200). To see past it, ' +
            'narrow the filters. Not with summary.',
        },
      },
      additionalProperties: false,
    },
  },
];

// LOCAL-EXEC self-management tool (card 5089). NOT part of TOOL_DEFINITIONS — those are
// proxied to the bridge; this one is spawned locally and never leaves the host. The
// description carries the operational guardrails as usage guidance for the model.
const CLEAR_CONTEXT_TOOL = {
  name: 'clear_context',
  description:
    "Clear THIS agent's context to save tokens. Run it as the FINAL ACTION of a turn, " +
    'AFTER you have committed your work and posted any handoff/status. NEVER call it ' +
    'mid-task, with uncommitted work, or while a human message is unanswered or queued. ' +
    'It EXECUTES a clear you have ALREADY DECIDED on — it does not decide for you. ' +
    'Local-exec only (never proxied to the bridge); it returns immediately and the clear ' +
    'terminates this session. No arguments.',
  inputSchema: { type: 'object', properties: {}, additionalProperties: false },
};

// The per-uid + per-server-name default is entry.mjs's channelSocketPath. Multi-agent
// operators get distinct paths automatically when they set distinct BRIDGE_CHANNEL_NAME
// per agent (the same string that's used as the .mcp.json key AND the
// <channel source="..."> attribute the model routes on).
const SERVER_PORT = Number(process.env.BRIDGE_CHANNEL_PORT || 8788);
const SERVER_HOST = '127.0.0.1';
const SOCKET_PATH = channelSocketPath(process.env);

// A bind failure exits the process with a stderr message Claude Code SWALLOWS
// (it does not surface MCP-server startup stderr), so a session whose connector
// loses the bind race comes up DEAF to live-wake invisibly. Leave a VISIBLE
// marker file in addition to stderr (FR #2444). The connector that SUCCESSFULLY
// binds owns the channel and clears any stale marker, so the signal reflects the
// current holder, not a week-old failure.
//
// Transport-aware path: the UNIX marker is the socket's sibling `<socket>.FAILED`
// — the path `bridge:check` derives from the agent's `channel.socket`. The HTTP
// marker is keyed by name+port (never masquerading as a socket failure); the
// launcher surfaces it on the agent host, and `bridge:check` does too when run
// there (best-effort) while its cross-host signal is the liveness probe of the
// loopback/tunnel port. Base dir: $XDG_RUNTIME_DIR when set (Linux), else
// os.tmpdir() — $TMPDIR or /tmp on Linux/macOS, %TEMP% on Windows — so the
// Windows launcher's $env:TEMP lookup and this path agree (a literal '/tmp'
// would resolve to C:\tmp under Node on Windows and never match). The path is
// entry.mjs's failureMarkerPath — ONE definition, because entry.mjs writes the same
// marker when no release can be started.
function markerPath() {
  return failureMarkerPath(process.env);
}

function writeFailureMarker(reason) {
  try {
    fs.writeFileSync(
      markerPath(),
      `${new Date().toISOString()} pid=${process.pid} ${SERVER_NAME}: ${reason}\n`,
      { mode: 0o600 },
    );
  } catch {
    // Best-effort — the stderr message is still emitted regardless.
  }
}

function clearFailureMarker() {
  try {
    fs.rmSync(markerPath(), { force: true });
  } catch {
    // Best-effort.
  }
}

// Startup config-validation refusals all share the same failure contract: leave
// a `.FAILED` marker (Claude Code swallows this server's startup stderr, so a
// bare exit is invisibly deaf to live-wake — FR #2444), emit the operator-facing
// stderr line, then exit non-zero. Route every refuse-and-exit site through here
// so no site can drift back to a marker-less exit. `advice` is the optional,
// site-specific remedy appended to the stderr line.
function refuseDeaf(reason, { advice } = {}) {
  writeFailureMarker(reason);
  console.error(`[${SERVER_NAME}] ${reason}${advice ? ` ${advice}` : ''}`);
  process.exit(2);
}

// THE ONE EADDRINUSE BODY — both transports, the marker AND the stderr line.
//
// ⭐ IT ENUMERATES; IT DOES NOT PICK. All this process measured is that the address
// would not bind. Naming one cause ("another session holds the channel") is a claim
// it cannot establish, and it was wrong on the case that was actually measured
// (roundtable #420: the holder was THIS session's own previous channel server, still
// running after `provision-board-tools.py --role b` rewrote `.mcp.json`). Telling
// that operator to "close the duplicate session" sends them after a session that
// does not exist. A bind-time connect-probe could discriminate (1) from (2) from
// (3); it is deliberately not here, so the honest form is the list plus every
// remedy, and the reader decides which one they are in.
//
// ⚠ ONE LINE, NO EMBEDDED NEWLINE. `bridge:check` interpolates this body into a
// single check finding (`ChannelTransportCheck`), and the launcher `sed`s it into
// its own warning block — a second line renders as an orphan there.
//
// No `transport` parameter, by design: the body is identical for unix and http, and
// `addr` already says which one this is. Cause (3) is marked `[unix only]` in the
// text rather than branched, so the two transports cannot drift apart.
// ⛔ THE TOKEN `/mcp reconnect` MUST STAY IN ONE LITERAL. Splitting it across the `+`
// boundary drops this file out of ActivationPhraseLockstepTest's census silently — the
// phrase may then drift with nothing red. Splitting the rest of the phrase is safe (the
// file stays a carrier and reds as an offender).
function unbindableReason(addr) {
  return (
    `EADDRINUSE binding ${addr} — not bindable. Causes include: ` +
    `(1) this session's previous channel server after re-provisioning ` +
    `(/mcp reconnect does not stop the previous channel server — restart the session); ` +
    `(2) another Claude Code session or another process holding it ` +
    `(close it, or set BRIDGE_CHANNEL_PORT / BRIDGE_CHANNEL_SOCKET); ` +
    `(3) [unix only] a leaked socket file — or any other file — occupying the path, ` +
    `with no listener (rm it only if you are sure no server is running). ` +
    `THIS Claude Code session is deaf to live-wake until then.`
  );
}

if (TRANSPORT === 'unix' && !SOCKET_PATH) {
  // markerPath() falls to its non-unix branch here (SOCKET_PATH is falsy), and
  // XDG_RUNTIME_DIR is necessarily unset in this state (it's the only reason
  // channelSocketPath() returned null), so the marker resolves to
  // os.tmpdir()/…http-<port>.FAILED — %TEMP% on Windows, where the launcher
  // looks. Write it so a misconfigured Windows seat isn't silently deaf (FR #2444).
  const remedy =
    process.platform === 'win32'
      ? `On Windows, Node rejects a filesystem socket path (EACCES on bind), so BRIDGE_CHANNEL_SOCKET ` +
        `is not a usable remedy here — set BRIDGE_CHANNEL_TRANSPORT=http to use the loopback HTTP listener instead.`
      : `Set BRIDGE_CHANNEL_SOCKET to an absolute path under a directory you own (mode 0700 preferred), ` +
        `or export XDG_RUNTIME_DIR, or set BRIDGE_CHANNEL_TRANSPORT=http to use the HTTP listener instead.`;
  refuseDeaf(
    `BRIDGE_CHANNEL_TRANSPORT=unix but BRIDGE_CHANNEL_SOCKET and XDG_RUNTIME_DIR are both unset — ` +
      `no socket path could be resolved, so THIS Claude Code session is deaf to live-wake`,
    { advice: remedy },
  );
}

if (TRANSPORT !== 'unix' && TRANSPORT !== 'http') {
  refuseDeaf(
    `BRIDGE_CHANNEL_TRANSPORT must be 'unix' (default) or 'http' (got '${TRANSPORT}') — ` +
      `THIS Claude Code session is deaf to live-wake`,
  );
}

// The board-tools transport is single-valued per seat (v1). This refuse runs
// UNCONDITIONALLY — OUTSIDE the TOOLS_ENABLED guard (DR2-5) — so a
// BRIDGE_CHANNEL_TOOLS=0 seat with both env vars set is still caught, not silently
// skipped past the advertise gate.
if (TOOLS_SSH_TARGET !== '' && TOOLS_ENDPOINT !== '') {
  refuseDeaf(
    `BRIDGE_TOOLS_SSH_TARGET and BRIDGE_TOOLS_ENDPOINT are both set — ` +
      `choose exactly ONE board-tools transport (single-valued per seat) — ` +
      `THIS Claude Code session is deaf to live-wake`,
  );
}

// What this launch's client update did (card#10568, design §3.4): entry.mjs writes
// <AWB_CLIENT_ROOT>/state.json for the launch before it imports this server, and one
// INSTRUCTIONS line says so when the state is not `current`. Read once, here — fixed for
// the session. A server started any other way has no AWB_CLIENT_ROOT and says nothing.
function readClientUpdateState(root) {
  try {
    return JSON.parse(fs.readFileSync(path.join(root, 'state.json'), 'utf8'));
  } catch (err) {
    return { unreadable: err && err.message ? err.message : String(err) };
  }
}

const CLIENT_UPDATE_LINE = process.env.AWB_CLIENT_ROOT
  ? clientUpdateInstruction(readClientUpdateState(process.env.AWB_CLIENT_ROOT), {
      launchId: process.env.AWB_LAUNCH_ID,
      root: process.env.AWB_CLIENT_ROOT,
    })
  : null;

const INSTRUCTIONS = [
  ...(CLIENT_UPDATE_LINE ? [CLIENT_UPDATE_LINE] : []),
  `Events from the agent-webhook-bridge arrive as <channel source="${SERVER_NAME}" kind="..." target_id="...">.`,
  'The body is JSON: {"intent": {kind, subject_id, summary, payload, ...}}; the kind and target_id attributes are copied from it (target_id is intent.subject_id) and are absent when a body carries no intent.',
  'These channel EVENTS are one-way notifications: read them and act — no reply is sent back through the event.',
  'kind identifies what happened upstream (e.g. new_card, column_move, content_edit); target_id names the resource the event is about; summary describes the event in prose; payload carries kind-specific data.',
  ...(TOOLS_ENABLED
    ? [
        `This server ALSO exposes request/response board tools scoped to YOUR channel identity: ${TOOL_DEFINITIONS.map((tool) => tool.name).join(', ')} —`,
        'call them to see, capture, fix or annotate board work without a kanban token (each tool\'s own description says what it does);',
        'never mint a second card to say the first is wrong — correct it, or comment on it;',
        'every write is confined by the bridge to your own board, and each tool\'s description states its scope.',
      ]
    : []),
  ...(CLEAR_CONTEXT_ENABLED
    ? [
        'This server ALSO exposes clear_context — a LOCAL self-management tool that clears THIS',
        "agent's own context to save tokens. Call it ONLY as the final action of a turn, after you",
        'have committed work and posted any handoff; never mid-task or with a human message unanswered.',
      ]
    : []),
].join(' ');

const capabilities = { experimental: { 'claude/channel': {} } };
if (ADVERTISE_ANY_TOOL) {
  capabilities.tools = {};
}

const mcp = new Server(
  { name: SERVER_NAME, version: HANDSHAKE_VERSION },
  {
    capabilities,
    instructions: INSTRUCTIONS,
  },
);

// Register the tools surface BEFORE connect so the capability and its handlers
// are live from the first request. A structured refusal (not a thrown error)
// names the activating config when the bridge endpoint/bearer is half-set, so a
// caller reaching a partially-configured install gets an actionable message.
// `scrubSnippet` + `relayBridgeResponse` (the credential-scrubbing relay contract)
// are pure — they live in ./channel-lib.mjs and are imported at the top of this file.

// SSH-forced-command transport: one round trip through channel-lib's sshRoundTrip (the
// same primitive the updater uses), relayed as a tool result. The child's stdout is
// CAPTURED, never inherited — this server's OWN stdout is the MCP JSON-RPC frame channel.
async function callToolOverSsh(payload) {
  const r = await sshRoundTrip({
    target: TOOLS_SSH_TARGET,
    key: TOOLS_SSH_KEY,
    port: TOOLS_SSH_PORT,
    input: payload,
    deadlineMs: TOOLS_SSH_DEADLINE_MS,
    stderrLimit: SSH_STDERR_CAPTURE_LIMIT,
    // The RAW stream stays diagnostics-only — never mixed into the tool result; only the
    // scrubbed, bounded head can reach a caller, and only on a failed leg (card#7709).
    // `console.error` reaches the MCP client's server log, not a surface the agent reads.
    onStderr: (text) => console.error(`[${SERVER_NAME}] ssh ${TOOLS_SSH_TARGET} stderr: ${text.trimEnd()}`),
  });
  if (r.failure) {
    // One error shape for every ssh failure (spawn, child error, deadline) — the isError
    // result the parse-failure/relay path yields.
    return { isError: true, content: [{ type: 'text', text: r.failure.message }] };
  }
  const captured = r.stderrHead.trim();
  // `code` is null when the child died on a signal, so it is reported as one rather than
  // as `exited null` (a wrong-but-specific cause is worse than an honest one).
  const how = r.code === null ? `ssh was killed by ${r.killSignal}` : `ssh exited ${r.code}`;
  return relayBridgeResponse(
    r.stdout,
    r.code === 0,
    `ssh ${TOOLS_SSH_TARGET}`,
    r.code === 0 ? '' : `${how}${captured ? `: ${captured}` : ' and wrote nothing to stderr'}`,
  );
}

// HTTP loopback transport: POST the call body with the per-agent bearer.
async function callToolOverHttp(payload, token) {
  try {
    const res = await httpRoundTrip({ url: TOOLS_ENDPOINT, token, body: payload });
    return relayBridgeResponse(res.text, res.ok, TOOLS_ENDPOINT);
  } catch (err) {
    return {
      isError: true,
      content: [
        {
          type: 'text',
          text: `could not reach the bridge tool endpoint ${TOOLS_ENDPOINT}: ${errorDetail(err)}`,
        },
      ],
    };
  }
}

// LOCAL-EXEC self-management (card 5089): spawn the clear-agent.sh helper DETACHED and
// return immediately — this NEVER proxies to the bridge (orthogonal to the board tools).
// The clear terminates THIS session, so we do not await the child; detached + unref +
// ignored stdio let it outlive this process's stdio pipe. Called-but-not-armed ($STY unset
// or the helper absent) returns a STRUCTURED MCP error, never a silent no-op.
function handleClearContext() {
  const helper = resolveOnPath(CLEAR_AGENT_HELPER);
  if (!process.env.STY || !helper) {
    const missing = [
      process.env.STY ? null : '$STY is unset (no GNU screen session detected)',
      helper ? null : `${CLEAR_AGENT_HELPER} is not on PATH`,
    ].filter(Boolean);
    return {
      isError: true,
      content: [
        {
          type: 'text',
          text: `clear_context is not armed on this seat: ${missing.join(' and ')}. No clear was run.`,
        },
      ],
    };
  }
  try {
    const child = spawn(helper, [], { detached: true, stdio: 'ignore' });
    child.unref();
  } catch (err) {
    return {
      isError: true,
      content: [
        {
          type: 'text',
          text: `clear_context could not spawn ${helper}: ${err && err.message ? err.message : err}`,
        },
      ],
    };
  }
  return {
    content: [
      {
        type: 'text',
        text: `clear_context: spawned ${helper} (detached) — this session will be cleared momentarily.`,
      },
    ],
  };
}

if (ADVERTISE_ANY_TOOL) {
  mcp.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: [
      ...(TOOLS_ENABLED ? TOOL_DEFINITIONS : []),
      ...(CLEAR_CONTEXT_ENABLED ? [CLEAR_CONTEXT_TOOL] : []),
    ],
  }));

  mcp.setRequestHandler(CallToolRequestSchema, async (request) => {
    const toolName = request.params.name;

    // clear_context is LOCAL-EXEC and orthogonal to the board tools — branch BEFORE the
    // bridge proxy (ssh/http) so it never leaves this host, even when board tools are on.
    // Handled unconditionally (not gated on CLEAR_CONTEXT_ENABLED) so a not-armed call
    // gets the structured "not armed" error instead of being proxied as a board tool.
    if (toolName === 'clear_context') {
      return handleClearContext();
    }

    // Past here it's a board tool. If board tools are not enabled on this seat, only
    // clear_context was advertised, so any other name is unknown — never proxy it.
    if (!TOOLS_ENABLED) {
      return {
        isError: true,
        content: [{ type: 'text', text: `unknown tool '${toolName}'` }],
      };
    }

    const args = request.params.arguments || {};
    // ⚑ THE KEY IS OMITTED, NEVER SENT AS null, when this server could not read its own
    // manifest. "Not reported" is the ABSENCE of the key on the bridge side — every client
    // predating this field produces exactly that shape — and a null would be a second
    // spelling of one state, which is the read-time fork the bridge should never have to
    // handle.
    // `launch` follows the same rule: omitted, never null, for a server entry.mjs did not
    // start (card#10568).
    const payload = JSON.stringify({
      tool: toolName,
      args,
      ...(CLIENT_VERSION === null ? {} : { client_version: CLIENT_VERSION }),
      ...(LAUNCH === null ? {} : { launch: LAUNCH }),
    });

    // Guard branches on the TRANSPORT (DR2-5), not on a bearer: the ssh transport
    // carries no bearer, so `!token` must not gate it.
    if (TOOLS_SSH_TARGET) {
      return await callToolOverSsh(payload);
    }

    const token = resolveToolsToken(process.env);
    if (!TOOLS_ENDPOINT || !token) {
      const missing = [
        TOOLS_ENDPOINT ? null : 'BRIDGE_TOOLS_ENDPOINT',
        token ? null : 'BRIDGE_TOOLS_TOKEN (or BRIDGE_TOOLS_TOKEN_FILE)',
      ].filter(Boolean);
      return {
        isError: true,
        content: [
          {
            type: 'text',
            text:
              `board tools are advertised (BRIDGE_CHANNEL_TOOLS=1) but not fully configured on this ` +
              `channel server: set ${missing.join(' and ')}. No call was made to the bridge.`,
          },
        ],
      };
    }

    // Dumb pipe: forward {tool, args, client_version} verbatim, no retry, no board logic.
    // Still a dumb pipe with the third key: `client_version` is this server's own manifest
    // version (read once, above), NOT anything derived from the call — no board logic, no
    // retry, and nothing about the request influences it.
    return await callToolOverHttp(payload, token);
  });
}

await mcp.connect(new StdioServerTransport());

if (TOOLS_ENABLED) {
  const target = TOOLS_SSH_TARGET
    ? `ssh:${TOOLS_SSH_TARGET}`
    : TOOLS_ENDPOINT || '(BRIDGE_TOOLS_ENDPOINT unset)';
  const why =
    CHANNEL_TOOLS_ENV === '1'
      ? 'BRIDGE_CHANNEL_TOOLS=1'
      : TOOLS_SSH_TARGET
        ? 'ssh target present (default-on)'
        : 'endpoint+bearer present (default-on)';
  console.error(
    `[${SERVER_NAME}] board tools ENABLED (${why}) — proxying tools/call to ${target}`,
  );
}

if (CLEAR_CONTEXT_ENABLED) {
  console.error(
    `[${SERVER_NAME}] clear_context ENABLED (local-exec; $STY set + ${CLEAR_AGENT_HELPER} on PATH)`,
  );
}

// Per the channels spec, the `meta` keys we send must match this regex —
// Claude Code silently drops any key containing other characters. Values
// can be arbitrary strings. We hard-code the two keys we set ('kind' and
// 'target_id', both valid identifiers), so the regex isn't load-bearing
// here, but we keep it exported as a reference for operators adding more
// keys downstream.
const VALID_META_KEY = /^[A-Za-z0-9_]+$/;

// `deriveMeta` (envelope → meta parsing) is pure — it lives in ./channel-lib.mjs
// and is imported at the top of this file.

// Exported for operators copying this server as a starting point — when
// they add new meta keys, the regex says what's safe to use without being
// silently dropped on the Claude Code side.
export { VALID_META_KEY };

// ⭐ THE DECLARE LEG (canon #7): THIS TRANSPORT CARRIES NO DELIVERY RECEIPT, AND THE 202
// SAYS SO ON THE WIRE. `mcp.notification()` resolves once the notification has been
// WRITTEN to the stdio transport; nothing in the notification contract reports back
// whether the Claude Code session on the far end ingested it, and this server has no
// other channel to learn that from. So a 202 here means ACCEPTED BY TRANSPORT, never
// `delivered` — and whether a session that is mid-turn sees the notification at its next
// turn boundary or never sees it at all is NOT ESTABLISHED, so this server claims
// neither. The declaration goes out on every surface that has its own reader, and each is
// DERIVED from the constants below rather than restated — so the surface set is whatever
// `grep -nE 'DELIVERY_RECEIPT_HEADER|ACCEPTED_UNCONFIRMED' <this file>` returns, never a
// number written here that the next surface added would falsify. Today the readers are: the
// HEADER is what the bridge parses (App\Bridge\Handlers\ChannelPushHandler reads it and
// reports what this end declared, or that this end declared nothing), and the BODY is
// what an operator sees running the README's curl smoke test. A comment alone would not
// do: it lives in a repo the bridge cannot read and the operator is not standing in.
const DELIVERY_RECEIPT_HEADER = 'X-Channel-Delivery-Receipt';
const NO_DELIVERY_RECEIPT = 'none';
const ACCEPTED_UNCONFIRMED =
  'forwarded — accepted by transport (unconfirmed): written to the stdio transport, ' +
  'which returns no receipt that the session received it';

const server = http.createServer((req, res) => {
  if (req.method !== 'POST' && req.method !== 'PUT' && req.method !== 'PATCH') {
    res.writeHead(405, { 'Content-Type': 'text/plain' });
    res.end('method not allowed');
    return;
  }

  if (SHARED_TOKEN) {
    const auth = req.headers['authorization'] || '';
    if (auth !== `Bearer ${SHARED_TOKEN}`) {
      res.writeHead(401, { 'Content-Type': 'text/plain' });
      res.end('unauthorized');
      return;
    }
  }

  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', async () => {
    const body = Buffer.concat(chunks).toString('utf8');
    const meta = deriveMeta(body);
    try {
      await mcp.notification({
        method: 'notifications/claude/channel',
        params: { content: body, meta },
      });
      res.writeHead(202, {
        'Content-Type': 'text/plain',
        [DELIVERY_RECEIPT_HEADER]: NO_DELIVERY_RECEIPT,
      });
      res.end(ACCEPTED_UNCONFIRMED);
    } catch (err) {
      // A throw here means the write itself failed — typically the stdio transport is
      // gone (Claude Code session closed). The bridge's channel_push raises on any
      // non-2xx and records the throw as a best-effort handler note beside an otherwise
      // completed dispatch; it does NOT retry, and no drain exists on this path, so this
      // 503 is a REPORT and not a request to redeliver. Its counterpart is the 202
      // above: the two together say the write happened or it did not, and neither says
      // anything about what the session did with it.
      res.writeHead(503, { 'Content-Type': 'text/plain' });
      res.end(`channel transport closed: ${err && err.message ? err.message : err}`);
    }
  });
});

// Lifecycle cleanup. The UNIX socket is a filesystem-pathname AF_UNIX socket, so
// a leftover file makes the NEXT direct bind() fail EADDRINUSE on Linux even with
// no listener behind it — which the launcher's stale-socket guard recovers from,
// but only after a <socket>.FAILED marker that misreads as a live concurrency
// collision. Node only runs the SIGTERM handler's cleanup by default, so terminal
// close (SIGHUP), Ctrl-C (SIGINT), and parent-pipe close (stdin EOF) leak the
// socket. Route every ordinary-quit path through one idempotent shutdown that
// unlinks explicitly. SIGKILL / hard crash can't run this — that's exactly what
// the launcher's stale-socket guard + the marker backstop are for (DL-154/155/157).
let cleanedUp = false;
let bound = false; // true once WE own the socket (set in the unix listen callback)
function shutdown(code) {
  if (cleanedUp) {
    return;
  }
  cleanedUp = true;
  try {
    server.close();
  } catch {
    // never listened / already closed
  }
  // Explicit synchronous unlink: server.close() drains open connections and
  // unlinks ASYNCHRONOUSLY, but process.exit() below is synchronous — so on a
  // SIGTERM with an in-flight bridge connection the process can exit before
  // close()'s unlink runs (the FR's "clean path" race). unlinkSync removes it
  // deterministically. Gated on `bound` so a signal during the EADDRINUSE
  // failure window never removes a stale socket we didn't create.
  if (TRANSPORT === 'unix' && SOCKET_PATH && bound) {
    try {
      fs.unlinkSync(SOCKET_PATH);
    } catch {
      // best-effort: already gone
    }
  }
  process.exit(code);
}

if (TRANSPORT === 'unix') {
  // Bind directly. On EADDRINUSE, refuse to start with an operator-actionable
  // message — no auto-unlink, no liveness-probe race. The message ENUMERATES the
  // causes rather than naming one — see unbindableReason(); "two concurrent
  // sessions on the same path" is only one of them, and not the measured one.
  server.on('error', (err) => {
    if (err && err.code === 'EADDRINUSE') {
      refuseDeaf(unbindableReason(`unix:${SOCKET_PATH}`));
    }
    // Any other bind error (notably the EACCES Win32 throws for a filesystem
    // socket path) would otherwise die on the bare throw below with no marker,
    // leaving a Windows seat silently deaf to live-wake. Capture err.code +
    // err.message so the real cause is diagnosable (FR #2444). markerPath()
    // resolves to <socket>.FAILED, a sibling of the path we failed to bind.
    writeFailureMarker(
      `failed to bind unix:${SOCKET_PATH} (${err && err.code ? err.code : 'unknown'}: ` +
        `${err && err.message ? err.message : err}) — THIS Claude Code session is deaf to live-wake`,
    );
    throw err;
  });
  // Set umask BEFORE listen() so the socket file is created with the
  // restricted perms atomically — closes the chmod race window where
  // the socket would briefly be world-readable/connectable between
  // listen() succeeding and a follow-up chmod call. Restored on both
  // success and error paths so subsequent runtime file ops aren't
  // affected by the temporarily-restrictive umask.
  const previousUmask = process.umask(0o077);
  server.on('error', () => {
    // Best-effort restore on the EADDRINUSE / other-error path. The
    // upstream 'error' handler will exit the process, but restoring
    // here keeps the contract clean if future code branches off
    // without exiting.
    process.umask(previousUmask);
  });
  server.listen(SOCKET_PATH, () => {
    // We own the channel now — clear any stale FAILED marker a previous deaf
    // session left, so the marker reflects the current holder (FR #2444).
    bound = true; // shutdown() may now unlink this socket — it's ours
    clearFailureMarker();
    // Restore umask for any subsequent file ops the runtime might do.
    process.umask(previousUmask);
    // Defense-in-depth: belt-and-suspenders chmod even though umask
    // should have done the right thing at bind time. On some kernels
    // umask doesn't apply to AF_UNIX socket creation.
    try {
      fs.chmodSync(SOCKET_PATH, 0o600);
    } catch (e) {
      console.error(`[${SERVER_NAME}] could not chmod socket: ${e.message}`);
    }
    console.error(
      `[${SERVER_NAME}] listening on unix:${SOCKET_PATH} ` +
        `(umask 0077 at bind; chmod 0600 defense-in-depth)`,
    );
    if (SHARED_TOKEN) {
      console.error(`[${SERVER_NAME}] bearer-token gating active (defense in depth)`);
    }
  });
} else {
  server.on('error', (err) => {
    if (err && err.code === 'EADDRINUSE') {
      refuseDeaf(unbindableReason(`http://${SERVER_HOST}:${SERVER_PORT}`));
    }
    // Any other bind error would otherwise die on the bare throw below with no
    // marker, leaving the seat silently deaf to live-wake. Capture err.code +
    // err.message so the real cause is diagnosable (FR #2444), mirroring the unix
    // fall-through. markerPath() resolves to the http-<port>.FAILED sibling.
    writeFailureMarker(
      `failed to bind http://${SERVER_HOST}:${SERVER_PORT} (${err && err.code ? err.code : 'unknown'}: ` +
        `${err && err.message ? err.message : err}) — THIS Claude Code session is deaf to live-wake`,
    );
    throw err;
  });
  server.listen(SERVER_PORT, SERVER_HOST, () => {
    clearFailureMarker();
    console.error(`[${SERVER_NAME}] listening on http://${SERVER_HOST}:${SERVER_PORT}`);
    if (SHARED_TOKEN) {
      console.error(`[${SERVER_NAME}] bearer-token gating active`);
    } else {
      console.error(
        `[${SERVER_NAME}] no token gating — relying on localhost-bind for the trust boundary. ` +
          `For multi-user hosts, set BRIDGE_CHANNEL_TOKEN or switch to BRIDGE_CHANNEL_TRANSPORT=unix.`,
      );
    }
  });
}

for (const sig of ['SIGTERM', 'SIGINT', 'SIGHUP']) {
  process.on(sig, () => shutdown(0));
}

// Parent-death without a signal: when Claude Code closes the stdio pipe on
// session teardown, this child's stdin reaches EOF. The MCP SDK keeps stdin
// flowing (it has a 'data' listener) but does NOT surface EOF via onclose, so
// listen for 'end' directly — a separate event from 'data', so no conflict with
// the SDK's reader, and no resume() needed (the SDK already put stdin in flowing
// mode). Without this an orphaned server lingers holding the socket, and the
// launcher's liveness probe then gets a live response and aborts.
process.stdin.on('end', () => shutdown(0));
// Defense-in-depth: if the MCP layer itself closes the transport, clean up too.
mcp.onclose = () => shutdown(0);
