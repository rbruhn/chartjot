# Chart Jot: NinjaTrader 8 AddOn Requirements

## Purpose

Build an NT8 AddOn that records a trader's notes throughout a manually traded
position and stages a completed round-turn trade, its execution details, and
an optional chart screenshot for the trader to review and explicitly submit to
the Chart Jot API.

The AddOn must support discretionary/manual chart trading. It must not depend
on a NinjaScript strategy being active.

The AddOn is the journal's primary data source. It sends each trade shortly
after it closes, once the trader submits it. Importing NT8 CSV exports is not
required; it may be added later only to fill gaps.

Items marked **(verify)** depend on NT8 API behavior that must be confirmed in
the technical spike (see [Spike to Verify](#spike-to-verify)) before the
related requirement is final.

## User Workflow

1. The trader opens a chart and opens the Chart Jot note panel.
2. They write notes before entering a trade.
3. The AddOn detects a position opening in the selected account/instrument and
   associates the pending notes with that trade.
4. The trader continues writing notes while the position is open.
5. The AddOn detects that the position has returned to flat, captures the
   chart, and stages the executions, legs, price excursion, and performance
   details with the notes as a trade that is **Ready to review**.
6. The trader reviews or finishes their notes, selects a trade type, then
   clicks **Submit trade**. The trader may open new trades before submitting;
   each closed trade waits in the staged-trades list independently.
7. The AddOn sends the staged trade to the configured Chart Jot endpoint.
   If delivery fails, it queues the payload and retries without losing the
   trade or notes.

The journal web application lets the trader add and edit notes after the trade
has been saved. The AddOn should preserve its original notes as timestamped
entries; it must not overwrite web-created notes.

## Configuration

Provide an AddOn settings surface with these values:

| Setting | Requirement |
| --- | --- |
| `Journal endpoint URL` | Required HTTPS base endpoint, e.g. `https://journal.example.com/api/v1/trades` |
| `Journal intake token` | Required bearer token copied from Chart Jot settings; mask in the UI and do not log it |
| `Data folder` | Where the AddOn stores its state file, delivery queue, screenshots and logs. Defaults to `%USERPROFILE%\ChartJot\` (visible in File Explorer next to Desktop/Documents, and outside the folders OneDrive's automatic backup redirects by default); the trader may change it. Settings screen includes an "Open folder" button and warns if the chosen folder is inside a cloud-sync folder (OneDrive, Dropbox, Google Drive) |
| `Instrument/chart scope` | The AddOn must clearly indicate which chart/instrument owns notes and screenshots |
| `Capture screenshot on close` | Enabled by default; allow a user to disable it |
| `Screenshot format/quality` | PNG by default; JPEG quality/resizing may be offered to reduce payload size |
| `Retry behavior` | Enabled by default; show queued/failed deliveries and permit manual retry |
| `Verbose diagnostics` | Disabled by default; when enabled, note content may be written to the diagnostic log |

Validate the endpoint as an absolute HTTPS URL in production. Do not embed a
token in a URL or query string.

When a Playback account is monitored, timestamps are the historical playback
times NT8 reports. They must be sent as-is and never replaced with wall-clock
time.

## Trade Detection and Correlation

### Required behavior

- Monitor account execution/order/position events appropriate for manual NT8
  trading (`Account.ExecutionUpdate`, `Account.OrderUpdate`,
  `Account.PositionUpdate`). Do not use strategy-only callbacks as the sole
  source of truth.
- Determine flat vs. open from a running signed sum of fills per
  account/instrument. Use `PositionUpdate` only as a consistency check, not as
  the trigger, because its ordering relative to execution events is not
  guaranteed **(verify)**.
- Detect transition from flat to non-flat as a trade opening.
- Detect transition from non-flat to flat as a trade closing.
- Support long and short positions.
- Support partial entries and partial exits. A completed trade may contain
  multiple entry and exit executions.
- Calculate weighted average entry and exit prices from executions when NT8
  does not provide completed-trade averages.
- Treat a reversal as two round-turn trades:
  1. close the prior position,
  2. begin a new trade in the new direction.

  NT8 flags the flipping fill with both `IsEntry` and `IsExit` set. It is split: the quantity needed to
  reach flat is allocated to the closing trade, and the remainder to the new
  trade (see `executions[].allocated_quantity`).
- Keep trade state separate by account and instrument. A close in one
  instrument must never complete notes or executions belonging to another.
- Every account's round turns are tracked independently. Only the trades a
  chart form submits are journaled: the Chart Trader account's, plus each copier
  follower's own trade, which carries the master's note and type (see "Copier
  followers").
- Ignore a fill whose `ExecutionId` has already been processed for that
  account. Reconnects can replay historical executions (verified on Rithmic:
  52 duplicates on reconnect; none on Sim). Treat `ExecutionId` as an opaque
  string; Rithmic IDs look like `9369100|2193543016|2193543016`.
- Do not build new trades from the startup/connect replay burst. After the
  AddOn starts or a connection reaches `Connected`, fills delivered as `new`
  can be hours old (`Time` well before the AddOn started or before the
  connection was established, `Execution.Order` null, `IsEntry`/`IsExit`
  false). Reconcile them against persisted open state and
  `Account.Executions` instead of treating them as live fills; only fills
  that arrive after the burst has settled and whose `Time` is at or after
  the AddOn started open or close journal trades. A fill delivered on
  reconnect for a position that was already open is still applied (see next
  item).
- Fills that occur while the connection is down (for example a target filled
  offline) are delivered on reconnect with `Execution.Time` equal to the
  delivery time, not the market time. Record that time as given, and mark
  the trade's excursion `complete = false`.
- Commission may be populated after the fill event. After the position
  reaches flat, wait a short settle period (default 2 seconds) and re-read
  commissions before staging. On Sim it was present immediately; the settle
  period stays as a cheap safeguard for live brokers. Staged values may still be refreshed until the
  trader submits.

### Stable source IDs

The AddOn must create identifiers that survive retries and application restarts:

| Value | Requirement |
| --- | --- |
| `trade_id` | Deterministic ID for one completed account/instrument round-turn trade (format below) |
| `execution_id` | NT8 `Execution.ExecutionId`, preserved as a string |
| `Idempotency-Key` | Equal to the stable `trade_id` for the HTTP request |

`trade_id` format:

```text
NT8-{account}-{instrument}-{hash}
```

- `{account}` and `{instrument}` are the NT8 account name and instrument full
  name with every character outside `[A-Za-z0-9]` replaced by `_`.
- `{hash}` is the first 16 lowercase hex characters of
  `SHA-256("{account}|{instrument full name}|{first entry ExecutionId}")`,
  using the raw names.

Because it is derived from the first entry fill, the same trade gets the same
ID after a restart or execution replay without persisting a counter. Never
generate a new `trade_id` for a retry. The server uses it to prevent duplicate
trades.

### Persistence of open state

Persist, not just the delivery queue, but all in-progress state: open
positions and their fills, pending pre-trade
notes, and staged trades awaiting
review, plus the running high/low price for each open position. On startup,
reload that state and reconcile it against
`Account.Executions` so a restart mid-trade neither loses notes nor changes
the `trade_id` or note phases.

Reconcile on every transition to `Connected`, not only at startup: at startup
the accounts may not be loaded, and a position can close while the AddOn is not
running (verified: after an NT8 restart the exit fills arrive as `new`
`ExecutionUpdate`s right after `Connected`, with the delivery time as `Time`).
Check the running sum against `Execution.Position` (signed, after the fill) on
every fill and resynchronize from `Account.Executions` on a mismatch. A fill
with `IsExit=True` must never open a trade. Do not call NT8 APIs that can block
(for example creating a `MarketData`) while holding `Account.Positions`,
`Account.Executions` or your own lock, and serialize the startup and connect
scans.

## Runners and Price Excursion

### Legs and runners

Traders often scale out: for example, buy 3, sell 2 at Target1, and leave 1
contract as a **runner** that either reaches a second target or is stopped out.
The journal must be able to show each part of the exit separately.

- A **leg** is all exit fills belonging to the same NT8 exit order (same
  `OrderId`). An exit order that fills in pieces is still one leg.
- Legs are numbered in the order their final fill occurred.
- A leg is a **runner** when at least one earlier leg of the same trade has
  already exited. In the example above, the Target1 leg is not a runner and
  the final 1-contract leg is.
- Each leg reports its quantity, average exit price, exit order name, exit
  reason, points, gross P&L, and its own price excursion (below).
- Only fills are recorded. Stop and target order modifications (for example,
  moving the stop to breakeven) are **not** tracked.

### Price excursion (MAE/MFE)

The fills only show where the trader got in and out. The journal also needs
**the most the trade was up** and **the most it was down** while it was open.
Example: buy at 7700, price rises to 7703, then falls to the 7698 stop. The
fills say −2 pt; the excursion shows the trade was +3 pt at its best.

- **MFE** (maximum favorable excursion): the furthest price moved in the
  trade's favor, in points from the average entry price. Never negative.
- **MAE** (maximum adverse excursion): the furthest price moved against the
  trade, in points from the average entry price. Never negative.
- While a position is open, subscribe to the instrument's live last-trade
  price and keep the highest and lowest prices since the first entry fill,
  including the fill prices themselves.
- For the whole trade, measure from the first entry fill until flat. For each
  leg, measure from the first entry fill until that leg's final fill.
- Points use the weighted average entry price of the trade.
- Persist the running high/low with the open-trade state. If the price feed
  was interrupted while the position was open (disconnect or NT8 restart),
  still send the values observed, but set `excursion.complete` to `false`.
- If no price data was observed at all, send `null` for the excursion values.
- MAE/MFE are measurements, not NT8 report values; they may differ slightly
  from NT8's Trade Performance report.

## Notes

### Trade form (decided 2026-10-04, #64)

One form per chart, opened from a **Chart Jot** button at the bottom of the
chart's Chart Trader panel (or, when that panel's layout is not available, at
the very end of the chart toolbar). It follows that chart's **Chart Trader** account and instrument; there is no
account or instrument picker. It has:

- One large note box. The trader writes in it before, during and after the
  trade; it is the same note throughout. Its text is saved locally as it is
  typed (never transmitted until Submit) and survives an NT8 restart.
- A **Trade type** dropdown (see below), with the `Other` description field.
- **Submit**: enabled once a trade on that account/instrument has closed and a
  trade type is chosen. It sends the trade(s) that closed there since the form
  was opened or last submitted/reset (the master) plus each copier follower's own trade (see "Copier followers"), all
  with the form's note and trade type, then clears the form.
- **Reset**: clears the note and type for the next idea. If a trade has closed
  and not been submitted, Reset drops it without journaling it (after a
  confirmation).
- One status line about the current trade only: waiting for entry, in trade
  (direction, size, time open), closed and ready to submit, and, after Submit,
  "Sending..." until the journal has it ("Not sent yet ... trying again" while
  it cannot be reached, up to 3 tries), then "Sent." for a few seconds, or
  "Not sent ... Click Retry" once the tries run out. Past submissions are not listed; only a
  failed one is shown, with **Retry**, because it needs action.
- A **Settings** button that opens Chart Jot Settings (the only way in; there
  is no Control Center menu item).

The form only cares about trades that close after it is first opened in an NT
session, or after its last Submit/Reset (decided 2026-10-05). The AddOn still
tracks every account's fills (it needs them for followers), but trades taken
before the form was opened are never offered, warned about, or sent; the 24-hour
prune drops them.

There is no staged-trades list and no separate pre/in/post-trade notes. The
note is sent as a single `general` note whose `occurred_at` is when the trader
started writing it. Closed trades nobody submits within 24 hours of staging are
dropped locally (they were never journaled).

### Copier followers (corrected 2026-10-04)

With the Affordable Indicators trade copier, the **master** is the Chart Trader
account the form follows. The note, trade type and screenshot are written or
captured once, on the master, and reused for every follower. Each account,
master and followers alike, still sends **its own trade data**: instrument,
entries, exits, legs/targets, P&L and excursion. Each is its own journal trade.

The AddOn reads the copier's follower list for the master from the copier
indicator (`aiDuplicateAccountActions`, its `ThisMasterAccount` and
`AllAccountData`) on any open chart, read-only. A follower's trade is the one
that matches the vendor-confirmed rules: same market family and contract month
(ES/MES, NQ/MNQ, YM/MYM, RTY/M2K, CL/MCL, GC/MGC), the master's direction (the
opposite when the follower fades), and an entry from 5 seconds before the
master's entry up to 5 seconds after it (Executions mode) or up to the master's
exit (Orders mode). The earliest such trade per follower is used.

### Trade type selector

The completed-trade review form must provide this dropdown:

| Submitted value | Display label | Implied direction |
| --- | --- | --- |
| `2ES` | Second Entry Short | short |
| `2EL` | Second Entry Long | long |
| `RS` | Range Short | short |
| `RL` | Range Long | long |
| `F2ES` | Failed Second Entry Short | long |
| `F2EL` | Failed Second Entry Long | short |
| `Other` | Other type of entry | — |

The AddOn must not infer the trade type from market data. The trader selects
the setup during review, and the selected value must remain editable until
submission. If the user selects `Other`, provide an optional free-text
description field named `trade_type_other`.

If the selected type's implied direction differs from the trade's actual
direction, show a non-blocking warning. Do not prevent submission. A failed
second entry is traded in the opposite direction (confirmed by the trader,
2026-10-04): `F2ES` is taken long and `F2EL` is taken short.

### Stop price

Optional, nullable, both on the AddOn side and the server -- never required,
and never used to gate submission. Exists so the trader has a record of what
they were actually risking, independent of the ATM's displayed stop at any
given moment (a trader using an ATM bracket sometimes moves the stop by hand
after entry, sometimes doesn't).

Auto-capture it best-effort, read-only (no order placement or modification,
consistent with every other rule in this document): while a position is
open, watch `Account.OrderUpdate` for `StopMarket`/`StopLimit` orders on that
account/instrument, and track the current working stop's price, updating it
whenever it changes (the trader drags it, or the ATM moves it to breakeven).
Whatever it is when the trade closes (or `null` if there never was one) is
the starting value. No cross-account or timing-based correlation is needed
here, unlike the now-removed copier matching -- it is the same account, same
instrument, and normally one open position at a time, so "the working stop
order while this trade's position was open" is unambiguous.

No input for this in the AddOn's review form (decided 2026-10-01) -- not
every trader wants to record it, and it should not be UI noise for the ones
who don't. The AddOn only ever sends what it silently auto-captured, exactly
as described above, or `null`. Correcting or adding a value after the fact is
a web-only concern, the same way editing a note already is: the journal web
app lets the trader edit a trade's `stop_price` after it has been saved, same
as it already does for notes. Submitted field: `stop_price`, decimal string,
nullable.

## Screenshot Capture

### Requirement

Capture **two** images per trade, both optional (a capture failure on either
must never prevent the trader from submitting the trade):

- **Entry screenshot**: captured shortly after the position opens (same short
  render delay as exit, below, so the entry fill/marker is drawn), **only when
  "Entry image" is ticked in Chart Jot Settings** (off by default; decided
  2026-10-04). Sent as `entry_screenshot_file` with an `entry_screenshot`
  object (`captured_at`). The journal shows it behind an "Entry Image" link
  under the exit image (#72). No **Recapture** for this one; it is a
  point-in-time record of what the setup looked like at entry.
- **Exit screenshot**: captured after a short render delay (default 1 second
  after flat) so the exit fill and execution markers are drawn. The trader
  can replace it with **Recapture** any time before submission -- this is the
  intended way to get a screenshot that includes drawings/markup added during
  review, rather than changing when the automatic capture happens. Capturing
  automatically at exit (not deferred to submit time) matters because the
  trade's instrument has to be the chart's *visible* tab at capture time (see
  "Originating chart"); by submit time the trader has very likely moved on to
  watching something else, so deferring the automatic capture would make
  "screenshot skipped" far more common. Both the immediate auto-capture and
  the review-time Recapture use the same chart-targeting logic.

Both captures use the same originating-chart logic (below) and the same
threading rules.

Images are written as PNG to `{Data folder}\images\{trade_id}.png` (exit) and
`{trade_id}-entry.png` (entry). Copier followers send their master's images:
at Submit the master's files are copied under each follower's trade_id
(see "Copier followers").

### Originating chart

The originating chart is chosen in this order:

1. For Recapture, the chart window whose Chart Jot form was used, if its
   visible tab shows the trade's instrument.
2. Otherwise, a chart window whose visible tab shows the instrument and whose
   Chart Trader account is the trade's account.
3. Otherwise, the most recently active chart window whose visible tab shows
   that instrument.
4. Otherwise, no screenshot; the trade is staged without it (logged as
   "image skipped").

"Visible tab shows the trade's instrument" is not a visual/title check --
match on `ChartTab.Instrument` directly (confirmed 2026-09-30, issue #37):
each tab in a chart window's `MainTabControl.Items` is a `TabItem` whose
`Content` is a `NinjaTrader.Gui.Chart.ChartTab`, which carries `Instrument`
(the actual instrument object), plus `TabName` (user-assigned, may be blank)
and `ActualTabName` (the resolved display name) if a label is ever needed for
logging/UI -- but matching itself should use `Instrument`, not either name
string. `MainTabControl.SelectedItem` identifies which tab is currently
visible; only that one counts as "shows the trade's instrument" (see
"Threading" below for why a background tab can't be substituted).

Ignore chart windows that host a non-trading dashboard indicator (for example
the Affordable Indicators Accounts Dashboard the trader runs separately for
managing a trade copier outside this AddOn). Such a window's data series (e.g.
NQ 12-26) only hosts the dashboard; it is not a trading chart, even though it
may show an instrument name. Detect it the same way the spike does: check
whether any of the window's chart controls host an indicator whose type name
is `NinjaTrader.NinjaScript.Indicators.aiDuplicateAccountActions`.

The panel always displays which chart owns its notes and screenshots.

### Threading

The screenshot method is `GetScreenshot(ShareScreenshotType)` on the chart
**window** (`NinjaTrader.Gui.Chart.Chart`, overriding `NTWindow`), not on
`ChartControl` (verified in `NinjaTrader.Gui.dll`). `ShareScreenshotType` has
`None`, `Chart`, `Grid`, `Tab`, and `Window`; use `Chart`. It captures the tab
currently visible in that window; a background tab cannot be captured without
switching to it, which the AddOn must not do.

There is a second overload, `GetScreenshot(ShareScreenshotType, FrameworkElement
customElement)`, which looks like it might let a background tab's own element
be captured directly instead. Tested live 2026-09-30 (issue #37) against a
real two-tab chart window, passing both the background `ChartTab` and its
`ChartControl` as `customElement`: both produced an identical image of the
*visible* tab, not the background one. The parameter does not do what its
name suggests here -- do not rely on it. A background tab genuinely cannot be
captured without switching to it, confirmed empirically, not just documented
from the original spike's single-overload test.

The call must run on the chart window's dispatcher. Perform PNG/JPEG encoding
and all network I/O away from that callback.

```csharp
BitmapSource screenshot = null;

chart.Dispatcher.Invoke(() =>
{
    screenshot = chart.GetScreenshot(ShareScreenshotType.Chart) as BitmapSource;
    if (screenshot != null && screenshot.CanFreeze)
        screenshot.Freeze();   // required before encoding on another thread
});

// Encode and upload outside the dispatcher callback.
```

### Screenshot metadata

Send one metadata object and one multipart file per captured image (entry
and/or exit -- either may be absent if its capture failed or was skipped):

- `captured_at`: ISO 8601 timestamp including offset.
- `caption`: optional user-editable caption; initially may describe chart
  interval/template when available. Exit only -- the entry screenshot is not
  shown in the review UI, so there is nothing to caption.
- `screenshot_file`: PNG or JPEG binary file multipart part.

Do not send a full-screen/window capture by default. Use chart-only capture so
unrelated desktop content is not included.

## Delivery Requirements

### Request

The future Chart Jot intake endpoint is:

```text
POST /api/v1/trades
Authorization: Bearer {journal-intake-token}
Idempotency-Key: {trade_id}
Content-Type: multipart/form-data
User-Agent: ChartJot-NT8/{addon-version}
```

The API implementation is not complete yet. Before shipping the AddOn, confirm
the production hostname, endpoint version, accepted file limit, and final
schema with the Laravel project.

Use multipart form data:

| Multipart field | Required | Content |
| --- | --- | --- |
| `trade` | Yes | UTF-8 JSON payload described below |
| `screenshot_file` | No | `image/png` or `image/jpeg` chart image |

Start delivery only after the trader clicks **Submit trade**. Use `HttpClient`
asynchronously with a finite timeout. Do not block the NT8 chart UI, account
event handlers, or dispatcher while waiting for an HTTP response.

### Success and retry behavior

- Treat HTTP `201 Created` as a new accepted trade.
- Treat HTTP `200 OK` as an idempotent retry accepted by the server.
- Record the server response and mark the local delivery as sent only after a
  successful response.
- For timeouts, connection errors, and HTTP `5xx`, retain the exact same
  staged request data and retry automatically with exponential backoff, **up
  to 3 attempts in all** (decided 2026-10-05: about 5s, then 10s apart). After
  the third, the delivery is Failed: the form says it could not be sent and
  offers **Retry**, which sends the same payload again with 3 fresh attempts.
  Retry covers **every** failed trade, not only the last Submit, and any form
  shows how many are waiting ("3 trades not sent ... Click Retry"; during a
  trade, a short note), so a trade can never be left stuck out of sight.
  The failed payload and its images stay saved across NT8 restarts until then.
- For HTTP `401` or `403`, stop automatic retries and show a configuration
  error; the trader must correct/replace the intake token.
- For HTTP `422`, retain the payload, show the validation response, and permit
  the trader to retry after resolving the configuration/data issue.
- Never discard unsent payloads merely because NT8 is closed or restarted.

Once the trader clicks **Submit trade**, the JSON payload is frozen and saved.
Retries send exactly that payload. Changing it after a `422` produces a new
frozen payload with the same `trade_id`.

### Local storage and secrets

- Store all AddOn data (state, queue, images, logs) under the configured
  `Data folder` setting, default `%USERPROFILE%\ChartJot\`. Unlike
  `%LOCALAPPDATA%`, this is visible in File Explorer by default, which
  matters because screenshots are named by `trade_id` specifically so a
  trader can find and manually upload one if a submission ever needs it.
  Do not default to `Documents\NinjaTrader 8`: Windows now commonly backs
  up `Documents` (and `Desktop`, `Pictures`) into OneDrive automatically
  ("Known Folder Move"), and a folder NT8 and the AddOn both write to
  constantly should not be fighting a sync client over file locks. Warn,
  but do not block, if the trader points `Data folder` at a path inside a
  detected cloud-sync folder.
- Encrypt the intake token with Windows DPAPI
  (`System.Security.Cryptography.ProtectedData`, `CurrentUser` scope). Never
  write it in plain text.
- Protect the bearer token from logs, crash reports, and screenshots.

## Platform and Architecture Constraints

- NT8 runs on .NET Framework 4.8. Explicitly enable TLS 1.2 or later for the
  HTTP client, and use one shared `HttpClient` instance.
- Avoid third-party DLL dependencies. For JSON, use
  `System.Web.Script.Serialization.JavaScriptSerializer` (System.Web.Extensions,
  which is in NT8's NinjaScript references). Newtonsoft.Json is not
  referenceable from NinjaScript on this install: `NinjaTrader.Custom.csproj`
  points it at a missing file (confirmed 2026-09-30: the reference entry
  exists, pointing at `D:\Documents\NinjaTrader 8\bin\Custom\Newtonsoft.Json.dll`,
  which does not exist there; the real `Newtonsoft.Json.dll` lives at
  `C:\Program Files\NinjaTrader 8\bin\`, a different path. Harmless as long as
  nothing does `using Newtonsoft.Json;` -- `addon/core`'s hand-rolled
  `JsonWriter`/`JsonReader` avoids the question entirely, which is exactly why
  they exist).
- **Referencing `ChartJot.Core.dll` from `addon/nt8` (confirmed 2026-09-30,
  the first live compile attempt):** dropping a DLL loose in
  `bin\Custom\` is **not** enough by itself for code that does
  `using ChartJot.Core;` and calls its types directly (unlike the copier DLL
  before it was removed, which was never referenced that way -- see below).
  NinjaScript's internal compiler (its own compiler, not the checked-in
  `NinjaTrader.Custom.csproj`, which is not what actually compiles a deployed
  script) tracks an explicit reference list, edited via a **References**
  window in the NinjaScript Editor (a toolbar button, not a menu -- the editor
  has no menu bar at all, only icons; look for one with a package/cube icon,
  confirmed to open a window titled "References" with an `add`/`remove` list
  and an "Applied" list of currently-referenced assemblies). Add the DLL there
  explicitly.
- **A netstandard2.0 library referenced this way also needs an explicit
  reference to `netstandard.dll` itself** (confirmed 2026-09-30), or every
  framework type it exposes in its public surface (`Object`, `Decimal`,
  `Enum`, `IList<>`, `Nullable<>`, ...) fails with CS0012 ("defined in an
  assembly that is not referenced"). The working copy on this machine:
  `C:\Windows\Microsoft.NET\Framework64\v4.0.30319\netstandard.dll`. Add it
  through the same References window.
- Keep trade reconstruction (fill aggregation, reversal splitting, averages,
  legs, excursion tracking), payload building, and the retry queue in plain C#
  classes with no NinjaTrader types, covered by unit tests outside NT8.
- The NT8-specific layer only adapts account and market-data events, hosts the
  WPF panel, captures screenshots, and exposes settings.

## Data Source

The AddOn is the journal's primary data source. It builds every value in the
payload from NT8 execution events and live prices; it does not read or
reproduce NT8's Trade Performance report.

[TRADES.md](TRADES.md) and [EXECUTIONS.md](EXECUTIONS.md) describe NT8's CSV
exports. They are reference material for a possible later CSV import that
fills gaps. The AddOn does not send those columns. A later import can match
Executions rows to AddOn trades exactly by the execution `ID` column, which the
AddOn sends as `executions[].execution_id` **(verify)**.

## Provisional Payload Contract

All numeric price and currency values must be serialized as strings, never
binary floating-point JSON numbers. Timestamps must be ISO 8601 with UTC
offset, using NT8's configured time zone (**Tools -> Options -> General**),
which may differ from the Windows time zone **(verify)**.

Worked example: a 3-contract long with a runner. Commission is $1.29 per
contract per side, and ES is $50 per point.

1. 9:30:00, buy 3 at 7700.00 (Entry).
2. Price dips to 7699.25, then rises.
3. 9:31:10, sell 2 at 7701.00 (Target1). 1 contract remains as the runner.
4. Price rises to 7703.25, then pulls back.
5. 9:36:20, sell 1 at 7702.50 (Stop1). Flat.

- Average exit: (2 × 7701.00 + 1 × 7702.50) / 3 = 7701.50, so points = 1.50
  and ticks = 6.
- Gross P&L: 2 × 1.00 × 50 + 1 × 2.50 × 50 = 225.00.
- Commission: 6 contract-sides × 1.29 = 7.74. Net P&L: 225.00 − 7.74 = 217.26.
- Trade excursion: MAE 0.75 (7699.25), MFE 3.25 (7703.25).
- Target1 leg: MAE 0.75, MFE 1.00 (the high was 7701.00 when it filled).
- Runner leg: MAE 0.75, MFE 3.25.

```json
{
  "schema_version": 1,
  "source": "ninjatrader_8",
  "addon_version": "1.0.0",
  "trade_id": "NT8-TEST_ACCT_001-ES_12_26-3f9c1a7b2d4e6f80",
  "account_name": "TEST-ACCT-001",
  "connection": "Rithmic",
  "trade_type": "2EL",
  "trade_type_other": null,
  "instrument": {
    "symbol": "ES",
    "contract": "ES 12-26",
    "tick_size": "0.25",
    "point_value": "50"
  },
  "direction": "long",
  "quantity": 3,
  "total_entry_quantity": 3,
  "entry": {
    "occurred_at": "2026-09-24T09:30:00-04:00",
    "average_price": "7700.00",
    "order_name": "Entry"
  },
  "exit": {
    "occurred_at": "2026-09-24T09:36:20-04:00",
    "average_price": "7701.50",
    "order_name": "Stop1",
    "reason": "stop"
  },
  "performance": {
    "points": "1.50",
    "ticks": 6,
    "gross_pnl": "225.00",
    "commission": "7.74",
    "fees": null,
    "net_pnl": "217.26"
  },
  "excursion": {
    "mae_points": "0.75",
    "mfe_points": "3.25",
    "max_adverse_price": "7699.25",
    "max_favorable_price": "7703.25",
    "complete": true
  },
  "stop_price": "7702.50",
  "legs": [
    {
      "sequence": 1,
      "runner": false,
      "exit_order_id": "ord-1002",
      "order_name": "Target1",
      "reason": "profit_target",
      "quantity": 2,
      "exited_at": "2026-09-24T09:31:10-04:00",
      "average_exit_price": "7701.00",
      "points": "1.00",
      "gross_pnl": "100.00",
      "mae_points": "0.75",
      "mfe_points": "1.00"
    },
    {
      "sequence": 2,
      "runner": true,
      "exit_order_id": "ord-1003",
      "order_name": "Stop1",
      "reason": "stop",
      "quantity": 1,
      "exited_at": "2026-09-24T09:36:20-04:00",
      "average_exit_price": "7702.50",
      "points": "2.50",
      "gross_pnl": "125.00",
      "mae_points": "0.75",
      "mfe_points": "3.25"
    }
  ],
  "executions": [
    {
      "execution_id": "a1b2c3d4e5f6",
      "order_id": "ord-1001",
      "occurred_at": "2026-09-24T09:30:00-04:00",
      "action": "buy",
      "role": "entry",
      "quantity": 3,
      "allocated_quantity": 3,
      "price": "7700.00",
      "commission": "3.87",
      "fee": null,
      "order_name": "Entry",
      "position_after": 3
    },
    {
      "execution_id": "a1b2c3d4e5f7",
      "order_id": "ord-1002",
      "occurred_at": "2026-09-24T09:31:10-04:00",
      "action": "sell",
      "role": "exit",
      "quantity": 2,
      "allocated_quantity": 2,
      "price": "7701.00",
      "commission": "2.58",
      "fee": null,
      "order_name": "Target1",
      "position_after": 1
    },
    {
      "execution_id": "a1b2c3d4e5f8",
      "order_id": "ord-1003",
      "occurred_at": "2026-09-24T09:36:20-04:00",
      "action": "sell",
      "role": "exit",
      "quantity": 1,
      "allocated_quantity": 1,
      "price": "7702.50",
      "commission": "1.29",
      "fee": null,
      "order_name": "Stop1",
      "position_after": 0
    }
  ],
  "notes": [
    {
      "body": "H2 at the EMA. Follow the plan.",
      "phase": "pre_trade",
      "occurred_at": "2026-09-24T09:29:40-04:00"
    },
    {
      "body": "T1 filled. Letting the runner work.",
      "phase": "in_trade",
      "occurred_at": "2026-09-24T09:31:25-04:00"
    },
    {
      "body": "Runner stopped. Good management.",
      "phase": "post_trade",
      "occurred_at": "2026-09-24T09:36:40-04:00"
    }
  ],
  "screenshot": {
    "captured_at": "2026-09-24T09:36:21-04:00",
    "caption": "5-minute ES with H2 at EMA"
  }
}
```

### Field requirements

| Field | Requirement |
| --- | --- |
| `schema_version` | Required; currently `1` |
| `source` | Required; `ninjatrader_8` |
| `addon_version` | Required AddOn semantic version |
| `trade_id` | Required deterministic round-turn source ID |
| `account_name` | Required NT8 account name |
| `connection` | Optional NT8 connection name |
| `trade_type` | Required: `2ES`, `2EL`, `RS`, `RL`, `F2ES`, `F2EL`, or `Other` |
| `trade_type_other` | Optional description; accepted only when `trade_type` is `Other` |
| `instrument.symbol` / `instrument.contract` | Required; `contract` is the NT8 instrument full name, e.g. `ES 12-26` |
| `instrument.tick_size` / `instrument.point_value` | Required decimal strings |
| `direction` | Required: `long` or `short` |
| `quantity` | Required positive integer: maximum absolute open position during the round turn |
| `total_entry_quantity` | Required positive integer: sum of allocated entry quantities (differs from `quantity` when scaling in after a partial exit) |
| `entry` | Required: first entry fill time, weighted average of all allocated entry fills, first entry order name |
| `exit` | Required: final (flattening) exit fill time, weighted average of all allocated exit fills, final exit order name and reason; timestamp must not precede entry |
| `performance.points` | Required: (average exit − average entry) for long, (average entry − average exit) for short |
| `performance.ticks` | Required integer: `points` ÷ tick size, rounded to the nearest tick |
| `performance.gross_pnl` | Required: sum over exit fills of (price difference × point value × allocated quantity), signed by direction |
| `performance.commission` | Required: sum of allocated fill commissions (pro-rated by quantity for a split reversal fill) |
| `performance.fees` | Decimal string when NT8 exposes fees separately from commission, otherwise `null` **(verify)** |
| `performance.net_pnl` | Required: `gross_pnl` − `commission` − `fees` |
| `excursion` | Required object; values `null` when no price data was observed; `complete` is `false` if the price feed was interrupted |
| `stop_price` | Optional decimal string; `null` when there was no stop or it is unknown. Never required, never blocks submission |
| `legs` | Required non-empty array, one per exit order, ordered by `sequence` |
| `legs[].runner` | `true` when an earlier leg of the same trade had already exited |
| `legs[].points` / `gross_pnl` | Measured from the trade's average entry price |
| `legs[].mae_points` / `mfe_points` | Excursion from first entry until that leg's final fill; `null` when unavailable |
| `executions` | Required non-empty array, including all entry and exit fills |
| `executions[].execution_id` / `order_id` | Required strings; unique per (`trade_id`, `execution_id`), not journal-wide |
| `executions[].quantity` | The fill's full quantity as reported by NT8 |
| `executions[].allocated_quantity` | Quantity of this fill belonging to this trade; less than `quantity` only for a reversal fill |
| `executions[].commission` | Commission for this fill (decimal string) |
| `executions[].fee` | Exchange/NFA fee for this fill (decimal string); `null` when `Execution.Fee` is zero or not reported |
| `executions[].position_after` | Signed position after this fill within this trade (positive long, negative short) |
| `notes` | Optional array; preserve order by `occurred_at` |
| `screenshot` | Optional metadata; include when `screenshot_file` is attached |

Weighted averages are computed in `decimal` and serialized with at most 8
decimal places, never with fewer decimal places than the tick size (e.g.
`7740.00` for ES).

### Exit reason

`exit.reason` and `legs[].reason` are one of `stop`, `profit_target`, `exit`,
or `other`, classified from the exit order name (case-insensitive). `stop` is
used rather than "stop loss" because a runner's stop often closes in profit.

| Order name | Reason |
| --- | --- |
| starts with `Stop` (e.g. `Stop1`, `Stop loss`) | `stop` |
| starts with `Target` or `Profit` (e.g. `Target1`, `Profit target`) | `profit_target` |
| `Exit`, `Close`, `Close position`, or `Flatten` | `exit` |
| anything else, including blank | `other` |

Always preserve the original NT8 order name. A blank name (chart-trader orders
without an ATM) is sent as `null`; the server accepts it and the trader can fill
it in on the trade page later.

## Error Reporting and Diagnostics

After a position closes, clearly label the trade **Ready to review**. Do not
clear the note field or staged trade when it becomes ready.

Display a concise user-facing result after each completed-trade submission:

- Instrument/account and close time.
- Screenshot captured or skipped.
- Delivery result and server status.
- Number of queued trades, if any.

Maintain a local diagnostic log under `{Data folder}\logs\`. It
includes the AddOn version, request timestamps, non-secret identifiers,
response statuses, and validation messages. Rotate log files daily and cap
their total size (default 20 MB). Never log the bearer token, full note content
unless verbose diagnostics is enabled, or raw image bytes.

## Acceptance Checklist

- [ ] A manual long trade with one entry and one exit posts exactly once.
- [ ] A manual short trade posts exactly once.
- [ ] Partial entries/exits produce correct weighted averages and include all
  fills.
- [ ] A scale-out trade produces one leg per exit order, with later legs
  marked as runners and correct per-leg points and P&L.
- [ ] `performance.net_pnl` equals `gross_pnl` − `commission` − `fees`, and the
  leg gross P&L values sum to `gross_pnl`.
- [ ] MAE/MFE are recorded for the trade and each leg from live prices; an
  interrupted feed sets `excursion.complete` to `false`.
- [ ] A reversal produces two separate completed trades, with the flipping
  fill's quantity split via `allocated_quantity`.
- [ ] Replayed/duplicate execution events do not create duplicate fills or
  trades.
- [ ] The form follows the chart's Chart Trader account and instrument, and its
  note (written before, during and after the trade) arrives as one note.
- [ ] A closed trade is staged for review and does not post until the trader
  clicks **Submit trade**.
- [ ] A copier follower's own trade is sent with the master's note and type.
- [ ] Reset clears the form; a closed, unsubmitted trade is then not journaled.
- [ ] A user must select one valid trade type before submission; a
  direction mismatch shows a non-blocking warning.
- [ ] Selecting `Other` permits an optional custom entry description.
- [ ] A successful submission clears the form for the next trade.
- [ ] A failed submission preserves the staged trade and notes for retry.
- [ ] A submitted trade posts without a screenshot if screenshot capture fails.
- [ ] Screenshot capture occurs on the chart dispatcher, includes drawings and
  the exit fill, and can be recaptured before submission.
- [ ] Network delivery never freezes the NT8 UI.
- [ ] Restarting NT8 while a position is open preserves its notes, excursion
  values observed so far, and yields the same `trade_id`.
- [ ] A failed request is retained across NT8 restart and retries with the same
  idempotency key.
- [ ] Replaying an already accepted request does not create a duplicate.
- [ ] Invalid/expired credentials are clearly reported without exposing the
  token; the token is stored only DPAPI-encrypted.
- [ ] The AddOn never mixes trades, notes, or screenshots across accounts or
  instruments.
- [ ] Trade reconstruction, legs, excursion, and payload building pass unit
  tests outside NT8.

## Spike to Verify

Confirm these in a throwaway NT8 AddOn before implementation hardens:

1. ~~`Execution` properties available to an AddOn, and whether `Commission`
   arrives late.~~ Resolved; see [SPIKE_FINDINGS.md](SPIKE_FINDINGS.md). All
   needed properties exist, including `Position` (signed, after the fill),
   `IsEntry`/`IsExit`, `IsInitialEntry`/`IsLastExit` and `Fee`. Commission was
   immediate on Sim.
2. ~~Ordering of `ExecutionUpdate` vs. `PositionUpdate`, and replay behavior on
   reconnect/startup.~~ Resolved: `ExecutionUpdate` precedes `PositionUpdate`;
   Sim does not replay on reconnect, Rithmic replays (deduplicate by
   `ExecutionId`), and startup delivers old fills as `new` (see
   [SPIKE_FINDINGS.md](SPIKE_FINDINGS.md)). AddOn restart with an open position
   also verified on Sim (no replay; reconcile from `Account.Executions` and
   `Account.Positions`).
3. ~~Whether `Execution.Time` is in NT8's configured time zone.~~ Resolved:
   `Execution.Time` is local time in `Globals.GeneralOptions.TimeZoneInfo`
   (Eastern on this install).
4. ~~Subscribing to live last-trade prices for an instrument from an AddOn
   (for MAE/MFE), including behavior on disconnect and in Playback.~~
   Resolved: works on Sim and Playback; a disconnect during an open trade is
   detectable and sets `excursion.complete = false` (see
   [SPIKE_FINDINGS.md](SPIKE_FINDINGS.md)).
5. ~~Whether `ExecutionId` matches the `ID` column of NT8's Executions export.~~
   Resolved: `Execution.ExecutionId` is identical to the CSV `ID` column —
   confirmed across all 9 fills from three accounts in the Playback session
   (see [SPIKE_FINDINGS.md](SPIKE_FINDINGS.md)). A CSV import can match by
   this ID exactly.
6. ~~Which JSON serializer can be referenced from an AddOn.~~ Resolved:
   `JavaScriptSerializer` (System.Web.Extensions). Newtonsoft.Json fails to
   compile in NinjaScript on this install.
7. Obtaining the owning `ChartControl` and instrument from an AddOn window
   hook (`OnWindowCreated`) works. `GetScreenshot` is on the `Chart` window and
   captures only the visible tab. Verified: the image includes indicators and
   drawing objects (see [SPIKE_FINDINGS.md](SPIKE_FINDINGS.md)).

## Open Items to Confirm With Laravel

1. Final production endpoint and API version.
2. Final multipart file-size and image-dimension limits.
3. The revised payload: `legs`, `excursion`, `performance.points`/`ticks`/
   `gross_pnl`/`fees`, `connection`, `executions[].position_after`,
   `allocated_quantity`, `order_id`, `total_entry_quantity`, and
   `addon_version`; `nt8_export` has been removed.
4. Exit reason value `stop` replaces `stop_loss`.
5. Execution uniqueness per (`trade_id`, `execution_id`) rather than
   journal-wide, to support reversal fills.
6. Trade detail UI: a legs/runner section and best/worst (MFE/MAE) display.
7. Whether notes should also be posted while a trade remains open through a
   separate draft-note endpoint.
