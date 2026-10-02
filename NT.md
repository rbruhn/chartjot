# Chart Jot: NinjaTrader AddOn work summary

Status as of 2026-09-30. This is a short summary of the NT8 AddOn work so far.
`NT8.md` remains the authoritative requirements and payload contract;
`SPIKE_FINDINGS.md` has the raw verification log; `SUMMARY.md` has the whole
project.

## Where it stands

- **Spike:** finished. Every NT8 behavior the AddOn depends on was checked on
  NinjaTrader 8.1.8.2 (Sim, Playback and Rithmic accounts). The live copier
  latency test that was still open is moot now (see "Trade copier removed"
  below).
- **AddOn core:** built and tested. Plain C# with no NinjaTrader types, in
  `addon/core` (namespace `ChartJot.Core`, `netstandard2.0`, C# 7.3), with 245
  xunit tests in `addon/tests`.
- **NT8 layer:** phase 1 built and verified live. `addon/nt8/ChartJot.cs`
  adapts real NT8 account/execution/connection events into `addon/core`'s
  `AddonState`, with reconcile-on-connect. No UI yet (note panel, screenshot,
  settings, HTTP submit are all still ahead — see "Next steps").
- **Server:** the Laravel intake endpoint exists and agrees with the AddOn
  through a shared contract test (see "Contract with the server").

## Trade copier removed (2026-09-29/30, issues #27/#28, PR #32)

The AddOn no longer reads or matches against the Affordable Indicators trade
copier at all. Reasoning: NinjaTrader's own copier dashboard already shows
failed/missed/still-open followers live, while trading — the one moment
that's actually actionable. The journal doesn't need to reconstruct any of
that after the fact; every account's trades are now tracked and reported
independently, with no master/follower distinction.

Removed from `addon/core`: `CopyMatcher.cs`, `CopierSnapshot.cs`,
`PartialTrade.cs`, `MarketFamilies.cs`, and their tests. `PayloadBuilder`,
`StagedTrade`, and `AddonState` were reworked so every account's completed
trade is tracked the same way. The Laravel side dropped `trade_copies`,
`trade_copy_executions`, and `trades.copies_source` the same way (companion
issue #27). `NT8.md` has been stripped of the copier section and all copier
payload fields to match.

Everything below this point that predates 2026-09-29 and mentions the copier
is historical record of work that was later removed, not current scope.

## What the spike proved

- **Fills and positions:** `ExecutionUpdate` always arrives about 15 to 20 ms
  before `PositionUpdate`. Counting fills is a reliable way to know when you are
  flat. `Execution.Position` is the signed position after the fill.
- **Reversals:** a flipping fill has both `IsEntry` and `IsExit` set, so it must
  be split across two trades.
- **MAE/MFE:** the execution's own high/low fields are unusable. Live `Last`
  ticks from `MarketData` while the trade is open are the right source.
- **Times:** `Execution.Time` is in NT8's configured time zone
  (`Globals.GeneralOptions.TimeZoneInfo`, Eastern on this install).
  `ExecutionId` is identical to the `ID` column in NT8's Executions CSV.
- **Screenshot:** `GetScreenshot(ShareScreenshotType.Chart)` on the chart window
  works, on the chart's dispatcher, and captures the visible tab only.

## Reconnect and restart (tested 2026-09-28)

- **Sim connection:** fills are not replayed on reconnect.
- **Rithmic connection:** fills are replayed on reconnect. They must be
  de-duplicated by `ExecutionId` (Rithmic IDs look like
  `9369100|2193543016|2193543016`, not hex).
- **After an NT8 or AddOn restart:** old fills arrive as `new` events. Exits that
  happened while the AddOn was not running arrive right after `Connected`, with
  the delivery time as `Execution.Time`. Building trades naively from these
  produced a phantom trade in the spike.
- **A trade that spans a disconnect** is flagged as having an interrupted price
  feed, so its MAE/MFE is marked incomplete.
- **Rules that came from this:**
  - Reconcile positions and executions on every transition to `Connected`, not
    only at startup.
  - Check the running position against `Execution.Position` on every fill and
    rebuild from `Account.Executions` if they disagree.
  - A fill flagged as an exit must never open a trade.
  - Never call a blocking NT8 API (for example creating a `MarketData`) while
    holding a lock. That hung NT8 in the spike.
  - Serialize the startup scan and the connect scan.

The restart test was on Sim only. A restart with an open position on a real
Rithmic account is still unverified.

## What is built (`addon/core`)

- **Trade tracker:** follows the position per account and instrument, splits
  reversals, ignores duplicates, rejects a fill whose reported position disagrees
  with the running sum, refuses to open a trade from an orphan exit, and can
  rebuild an open trade from `Account.Executions`.
- **Trade calculator:** weighted averages, points, ticks, gross, commission,
  fees and net; legs (one per exit order, later ones are runners); MAE/MFE for
  the trade and each leg. It reproduces the worked example in `NT8.md` exactly.
- **Payload builder:** the JSON for the intake request, written with a small
  hand-made JSON writer because NT8 cannot reference a JSON library reliably.
  Prices and money are strings. A blank order name is sent as `null`.
- **JSON reader/writer** (`JsonReader.cs`/`JsonWriter.cs`): a hand-rolled pair,
  since NinjaScript can't reliably reference a JSON library on this install
  (confirmed again 2026-09-30: `Newtonsoft.Json`'s reference entry in
  `NinjaTrader.Custom.csproj` points at a file that doesn't exist).
- **State persistence** (`AddonState.cs`, `StateFile.cs`, `DeliveryQueue.cs`,
  `PendingNotes.cs`, `StagedTrade.cs`): one atomically-written state file
  combining open-trade excursion (per fill, not just per trade), pending
  notes, staged trades, and the delivery retry queue. `AddonState.Reconcile`
  rebuilds fills from `Account.Executions` and is safe to call again on every
  reconnect, not just startup.
- **HTTP delivery** (`TradeDelivery.cs`): sends a `DeliveryQueue`'s due
  payloads per `NT8.md`'s contract (multipart, status-code mapping, retry
  semantics), with the response body read as a bounded byte stream so a huge
  or malformed response can't exhaust memory inside the NT8 process.

## Contract with the server

- The AddOn tests write five real payloads to `tests/Fixtures/addon/`. A test
  fails if the builder's output drifts from those files, and
  `tests/Feature/Api/AddonContractTest.php` posts each file to the real intake
  endpoint. Neither side can change the payload without the other noticing.
  Reconciled post-copier-removal in issue #33.
- To regenerate the fixtures after an intended change, run
  `PATS_WRITE_FIXTURES=1 dotnet test addon/tests` (the variable kept its old
  name).
- `connection` is still required by the server. If NT8 gives none, the AddOn
  sends `Unknown`.

## Defensive rules for the NT8 layer

An AddOn bug cannot place a wrong order, because it only reads events. It can
freeze NT8, which would affect trading. So:

- Wrap every event handler so an exception cannot escape into NT8.
- Keep network calls, screenshot encoding and file writes off NT8's event
  threads.
- Never call NT8 while holding a lock.
- Provide a setting that turns the journal off without removing the AddOn.
- Unhook every `MarketData.Update` handler on `State.Terminated`
  (`MarketData` has no `Dispose`).

## The NT8 layer (`addon/nt8/ChartJot.cs`)

Phase 1 — account adapters and reconciliation — built 2026-09-29, verified
live against real NT8 2026-09-30. No UI yet: correctness is observed through
a diagnostic log, the same way the spike itself was verified.

- Built on `spike/ChartJotSpike.cs`'s already-proven pattern: one `sync` lock
  guarding shared state, a `scanGate` serializing the startup scan against a
  reconnect scan, never calling into NT8 while holding either, and the same
  reflection technique for `Connection.ConnectionStatusUpdate` (a static event
  with no normal compile-time accessor).
- Adapts `Account.ExecutionUpdate`/`PositionUpdate` into `addon/core`'s `Fill`
  type and applies them via `AddonState.Tracker`. Reconciles against
  `Account.Executions` on startup and every reconnect via `AddonState.Reconcile`.
- State persists to a hardcoded `%USERPROFILE%\ChartJot\state.json` for now —
  the `Data folder` setting becomes configurable once the settings UI exists.
- `addon/nt8check`: a throwaway dev tool (`dotnet run`) that inspects the real
  NinjaTrader 8 assemblies via `System.Reflection.MetadataLoadContext` to
  confirm exact API signatures before writing code against them. Used to
  verify `Execution.MarketPosition` (not `Order.OrderAction`) is the correct
  signal for a fill's direction, among other signatures.

### Getting it to actually compile in NinjaScript (found 2026-09-30)

Cost real time the first time: a loose DLL in `bin\Custom\` is not
auto-referenced for code that does `using ChartJot.Core;`, and a
netstandard2.0 library needs `netstandard.dll` referenced too. Both are build
requirements now, not just a one-off finding — see `NT8.md`'s "Platform and
Architecture Constraints" for the exact mechanics (the References window,
file paths, and why `NinjaTrader.Custom.csproj` is a red herring).

The real NinjaTrader assemblies are reachable from this dev environment
through WSL2's Windows drive mounts (`/mnt/c/Program Files/NinjaTrader 8/bin/`),
which is how `addon/nt8check` and a real (non-NinjaScript) compile check of
`ChartJot.cs` against the genuine DLLs were both possible before ever
touching the live NT8 install.

### First live verification (2026-09-30)

A real Sim101 ES 12-26 trade, 2 contracts, long then flat:

- `[FILL] ... status=Applied` for both the entry and exit fill.
- `[TRADE] closed trade_id=NT8-Sim101-ES_12_26-ecd4684fc6b2430b net_pnl=-82.96`
  — correct `trade_id` format, correct net P&L (gross −75.00 minus 7.96
  commission).
- The written `state.json` had the full trade in `awaiting_stage`: correct
  entry/exit prices, correct `-04:00` timestamp offsets (confirms the
  `Globals.GeneralOptions.TimeZoneInfo` conversion works against a real
  fill), per-fill excursion ranges recorded, `excursion: null` /
  `excursion_complete: false` as expected (live tick subscription for MAE/MFE
  is deliberately out of scope for phase 1).
- Also observed: `Connection.ConnectionStatusUpdate` appears to replay the
  current connection status synchronously the moment a handler subscribes
  (`Connecting`/`Connected` were logged *before* the "Subscribed"
  confirmation line) — not a bug, just worth knowing when reading the log.

## Open issues (AddOn-related, as of 2026-09-30)

- **#14** — send `trade_type` in the AddOn payload. Blocked: needs the
  trade-type selector in the note panel UI, which doesn't exist yet.
- **#37** — screenshot capture must target the trade's own chart, not
  whichever tab is visible. Both open questions resolved 2026-09-30 (design
  only — capture code itself isn't built yet, so left open): `ChartTab`
  exposes `Instrument` directly (a `TabItem.Content` under
  `Chart.MainTabControl`), so matching uses that, not a name string. A
  background tab cannot be captured — tested live against a real two-tab
  window, `GetScreenshot`'s `customElement` overload still only captures
  whatever tab is actually visible, regardless of which element is passed.
  Confirms the spec's existing "visible tab only, otherwise skip" design was
  already correct. See `NT8.md`'s "Originating chart" and "Threading".
- **#52** — new 2026-09-30: capture an entry screenshot (sent to the server,
  not shown in the main web UI, for later API retrieval) in addition to the
  existing exit screenshot. Exit capture stays automatic at exit time, not
  deferred to submit time — Recapture already covers "I want to add drawings
  first" without the higher skip risk of waiting until submit, when the
  trader has likely moved off that chart tab.
- **#53** — new 2026-09-30: an optional, editable `stop_price` field,
  auto-captured best-effort by watching `Account.OrderUpdate` for stop orders
  while a position is open (read-only, no order placement/modification).
  Much simpler than #39's association problem: same account, same
  instrument, normally one open position at a time. Split 2026-10-01 into two
  cloud-feasible companion issues (neither touches NinjaTrader types):
  **#56** (`addon/core`) and **#57** (Laravel). **Both done and merged
  2026-10-01** (PR #58, PR #59) — reviewed and tested directly before each
  merge (pulled the branch, read the diff, ran the suite myself: 245/245 on
  `addon/core`, 51/51 on `php artisan test tests/Feature/Api` including
  `AddonContractTest`), not just going on the description. `stop_price` now
  flows end to end: `StagedTrade`/`SubmissionInfo` → `PayloadBuilder` → a new
  migration on `trades` → validated and stored by intake. `NT8.md`'s payload
  contract table and worked example updated to match.
  **#53 itself stays open**: all that's left is the actual
  `Account.OrderUpdate` watching in `addon/nt8/ChartJot.cs`, which needs real
  NinjaTrader assemblies and happens locally, not in the cloud.
  **Decided 2026-10-01: no edit input in the AddOn's review form** — not
  every trader wants to record this, and it shouldn't be UI noise for the
  ones who don't. The AddOn only ever sends what it silently auto-captured
  (or `null`); editing/adding a value after the fact is web-only, same as
  editing a note already is. `NT8.md`'s "Stop price" section updated.
  **#60** (new 2026-10-01, web-only) tracks that display/edit UI. Rescoped
  same day: not bundled into the big "Edit Trade" form — its own small
  "Edit Stop Price" link with an inline input, same standalone-toggle shape
  as note editing already uses (`editingNoteId`/`startEditNote`/`saveNote`),
  not the heavier `tradeEditForm` flow that also edits direction/quantity/
  prices/times together.
- **#39** — closed 2026-09-30, not needed. It existed to feed a future
  R-multiple stat; the trader's actual methodology (Mack's Price Action
  Trading) runs consistently high risk:reward by design, so that stat isn't
  useful here. The design questions (order-association, ATM vs. manual
  stops, trailing stops) are still accurately captured in the closed issue if
  this ever gets revisited.

## Next steps

1. #37's design is resolved (see above). #39 closed, not needed. #52/#53 are
   new design work, not started.
2. The rest of the NT8 layer: the WPF note panel, screenshot capture,
   settings, and wiring `TradeDelivery` to a Submit action. `TradeDelivery`
   drives `DeliveryQueue` from an NT8 background thread: `Due(now)` for what
   to send, `MarkSending`/`RecordResult` around each call, never on an NT8
   event thread.
3. Live checks that need a real broker: an AddOn restart with an open
   position on a Rithmic account (Sim-only so far).
4. #14 once the note panel/trade-type selector exists.

## Housekeeping

- The repository moved from `/data/web/patsjournal` to `/data/web/chartjot`.
- The spike source lives at `spike/ChartJotSpike.cs` in the repo and
  `NinjaTrader 8\bin\Custom\AddOns\ChartJotSpike.cs` on the live install —
  kept in sync under that one name (previously drifted: the live copy was
  still the pre-rename `PatsJournalSpike.cs` until re-synced 2026-09-29).
  Keep one name in both places so NT8 does not load two copies.
- The fixed spike (scan gate, no locks around NT8 calls, no `Dispose`) is what
  is in the repository.
- `feature/nt8-addon-core` merged into `development` via PR #42
  (2026-09-29/30). All AddOn work now happens directly on `development`
  behind per-issue feature branches, same as the rest of the repo.
