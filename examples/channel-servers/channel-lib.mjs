// Pure helpers for the reference channel MCP server (agent-webhook-bridge-channel.mjs).
//
// These functions are SIDE-EFFECT-FREE: they take every input as an argument and
// return a value with no I/O, no process.exit, no filesystem/network access, no
// process.env reads, and no closure over the server's startup constants. They are
// split out into this sibling module ONLY so they can be unit-tested directly —
// the main server self-executes on import (it binds a real transport and calls
// process.exit on refuse paths), so importing IT to reach these helpers is not an
// option. The imperative startup body, and any helper that reads env / does I/O /
// closes over startup state (resolveToolsToken, shouldAdvertiseTools, markerPath),
// stay in the main file unchanged.
//
// Consumers copy the WHOLE examples/channel-servers/ directory (see README), so
// this file travels with the entry point; the entry imports it with a relative
// `./channel-lib.mjs` specifier — no build step, plain ESM.

// Strip obvious credential substrings from a raw-body snippet before it is relayed
// into a tool result (a PHP trace could echo an Authorization/Bearer line).
export function scrubSnippet(body) {
  return String(body)
    .replace(/(authorization|bearer)[^\n]*/gi, '[redacted]')
    .slice(0, 500);
}

// The ONE relay contract for BOTH transports (DR2-2, canon #5 — fixed at the second
// caller). Accumulate the FULL body (DR2-9), then JSON.parse-or-isError. The success
// signal is LEG-SUPPLIED (res.ok / clean ssh exit), NEVER inferred from the body — a
// 200 (or exit 0) with a php-warning-prepended body is a CORRUPT result, so it is
// isError:true, not a silently-broken isError:false. On parse failure a truncated,
// credential-scrubbed snippet keeps a non-JSON 502 page diagnosable.
//
// `legDiagnostic` (card#7709) is the leg's own account of WHY it failed — the ssh exit
// code plus its stderr — and it is what makes the leg signal reachable on the
// parse-failure branch, which until now returned the snippet before ever reading
// `legSuccess`. That collapsed two different failures into one string, and for the
// case that actually happens (transport down: non-zero exit, empty stdout) the string
// was `non-JSON response from the bridge (ssh <target>):` with nothing after the colon,
// because `scrubSnippet('')` is `''`. The agent holding the failure could not name it;
// measured cost at one install was a board tool dead for 10 days.
//
// The two states stay SEPARATE, deliberately:
//   - failed leg WITH a diagnostic  ⇒ name the diagnostic (+ any partial output).
//   - clean leg, or a failed leg with no diagnostic to give (an HTTP non-ok that DID
//     return a body) ⇒ the original snippet message, unchanged. There the body IS the
//     diagnosis, so folding a transport note into it would only re-mint this defect
//     pointing the other way.
// Both arms scrub: a leg diagnostic is unvetted operator-facing text, same as a body.
export function relayBridgeResponse(rawBody, legSuccess, sourceLabel, legDiagnostic = '') {
  try {
    JSON.parse(rawBody);
  } catch {
    const transportFailed = !legSuccess && Boolean(legDiagnostic);
    // A leg that died before writing anything usually leaves an empty or
    // whitespace-only body; appending it as "partial output" would be noise.
    const partial = String(rawBody).trim();
    const text = transportFailed
      ? `the ${sourceLabel} leg FAILED: ${scrubSnippet(legDiagnostic)}` +
        (partial ? ` | partial output: ${scrubSnippet(partial)}` : '')
      : `non-JSON response from the bridge (${sourceLabel}): ${scrubSnippet(rawBody)}`;
    return { isError: true, content: [{ type: 'text', text }] };
  }
  return {
    isError: !legSuccess,
    content: [{ type: 'text', text: rawBody }],
  };
}

// Derive the channel `meta` keys from a raw request body. Best-effort JSON parse:
// a non-JSON body (or a body without an object `intent`) yields an empty meta,
// never a throw — the caller always gets a usable object.
export function deriveMeta(body) {
  const meta = {};
  try {
    const parsed = JSON.parse(body);
    const intent = parsed && typeof parsed === 'object' ? parsed.intent : null;
    if (intent && typeof intent === 'object') {
      if (typeof intent.kind === 'string') {
        meta.kind = intent.kind;
      }
      if (typeof intent.target_id === 'string') {
        meta.target_id = intent.target_id;
      }
    }
  } catch {
    // body is not JSON; meta stays empty
  }
  return meta;
}
