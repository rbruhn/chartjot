# Chart Jot

A personal trading journal for NinjaTrader 8 traders. Trades are sent
automatically from a custom NT8 AddOn via a REST API, or imported manually
from a CSV export of NT8's Trade Performance → Executions screen. Review
trades, add notes, attach screenshots, and track performance across accounts.

## Stack

- Laravel 13 · PHP 8.5
- Livewire 3 (Volt) · Alpine.js · Tailwind CSS 3.4
- SQLite (dev) · MySQL (prod)
- Laravel Horizon (Redis) for queues/scheduling

## Getting Started

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

php artisan migrate

npm run dev
php artisan serve
```

## NT8 AddOn

The companion NinjaTrader 8 AddOn lives in [`addon/`](addon/) and posts
trades to this app's API. See [`NT8.md`](NT8.md) for the AddOn
specification and [`WEB.md`](WEB.md) for the web application reference
(routes, data model, Livewire components, and key implementation notes).

- `addon/core/`: plain C# (no NinjaTrader types). This is where trade tracking,
  payloads, the delivery queue, the form and follower rules live. It builds
  `ChartJot.Core.dll`.
- `addon/nt8/ChartJot.cs`: the NinjaScript AddOn. It covers NT8 events, the
  chart form, settings, chart images and the background sender.
- `addon/tests/`: unit tests for `addon/core`.

### Installing or updating

Each GitHub Release has the AddOn attached as `ChartJot-AddOn-<version>.zip`,
a NinjaScript archive. To build it yourself, run `addon/package.sh`, which
writes it to `addon/dist/`. It contains:

| File | What it is |
| --- | --- |
| `AddOns\ChartJot.cs` | The AddOn source; NinjaTrader compiles it on import |
| `ChartJot.Core.dll` | `addon/core` built for .NET Framework 4.6.2 (it runs on NinjaTrader's 4.8), so no `netstandard` reference is needed |
| `AdditionalReferences.txt` | Tells NinjaTrader to reference `ChartJot.Core.dll` |
| `Info.xml` | NinjaTrader's export header |

To install it, in NinjaTrader's Control Center go to **Tools → Import →
NinjaScript…**, pick the zip and click **Import**. NinjaTrader copies the files
into `bin\Custom`, adds the reference and compiles. Reopen charts (or
restart) so they get the Chart Jot button.

NinjaTrader's own **Tools → Export → NinjaScript** doesn't produce a working
package. It records the `ChartJot.Core.dll` reference but leaves the DLL out,
so use `addon/package.sh`.

**By hand (development):**

1. **Close NinjaTrader.** It locks `ChartJot.Core.dll` while running, so the
   copy fails. A new `ChartJot.cs` with an old DLL shows compile errors such as
   "`TradeImages` could not be found".
2. Build the DLL: `dotnet build addon/core`.
3. Copy `addon/core/bin/Debug/net462/ChartJot.Core.dll` to
   `Documents\NinjaTrader 8\bin\Custom\`.
4. Copy `addon/nt8/ChartJot.cs` to `Documents\NinjaTrader 8\bin\Custom\AddOns\`.
5. Start NinjaTrader, open the NinjaScript Editor and press **F5**. Reopen
   charts (or restart) so they get the Chart Jot button.

If only `ChartJot.cs` changed, NinjaTrader can stay open: copy it and press
F5. The NinjaScript Editor's **References** must include `ChartJot.Core.dll`
(one-time setup, done for you by an import). An install from before #88 also
has a `netstandard.dll` reference; it's harmless and can stay. Any compile
error in another script, for example a strategy, blocks every script,
including Chart Jot.

### Uninstalling

Do it in this order, or NinjaTrader won't start. Its last compiled build still
contains Chart Jot and fails to load without `ChartJot.Core.dll`.

1. With NinjaTrader running, delete `bin\Custom\AddOns\ChartJot.cs` (or remove
   it in the NinjaScript Editor).
2. In the NinjaScript Editor's **References**, remove **ChartJot.Core** (and
   **netstandard**, if an install from before #88 added it).
3. Press **F5** to compile without Chart Jot.
4. Close NinjaTrader, then delete `bin\Custom\ChartJot.Core.dll`.

If NinjaTrader won't start because the DLL was deleted first, put
`ChartJot.Core.dll` back in `bin\Custom`, start NinjaTrader, and follow the
steps above.

Your settings, state and images in `%USERPROFILE%\ChartJot\` are left alone.

### First-time setup

Open a chart, click **Chart Jot**, then **Settings** on the form:

- **Journal endpoint URL**: from the journal's Settings page, NinjaTrader
  intake section (`https://…/api/v1/trades`). It must be `https://`.
- **Journal intake token**: click **Generate new token** on that page and paste
  it here. It's shown once. It's saved encrypted (Windows DPAPI) and the box
  clears after Save; leave it empty to keep the saved token. Generating a new
  token in the journal replaces the old one, so the AddOn needs the new one.
- **Data folder**: where state, images and queued trades are kept (default
  `%USERPROFILE%\ChartJot\`). Settings warns if it's inside OneDrive, Dropbox
  or Google Drive.
- **Entry image**: off by default. When ticked, an entry image is also taken
  (see below).

To test against a local copy of the journal, point the endpoint at it (for
example `https://chartjot.test/api/v1/trades`) with a token from that local
journal. Switching back needs a token from the real journal again.

### Using the form

- **Opening it.** The **Chart Jot** button sits at the bottom of the chart's
  Chart Trader panel. If that panel isn't available, it goes at the end of the
  chart toolbar. There's one form per chart.
- **What it follows.** The form follows that chart's **Chart Trader account
  and instrument**; there's nothing to pick.
- **Writing the note.** One note box: write before, during and after the
  trade. It saves as you type and survives a restart.
- **Trade type.** Pick one from the dropdown (2ES, 2EL, RS, RL, F2ES, F2EL,
  Other). If it implies the opposite direction to the trade, a warning
  appears, but you can still submit. F2ES is taken long and F2EL short.
- **Submit.** Enabled once the trade has closed and a type is chosen. It
  sends the trade with the note, type and images, then clears the form.
- **Reset.** Clears the form for the next idea. If a trade already closed and
  wasn't submitted, Reset drops it without journaling it, after asking first.
- **Which trades count.** The form only cares about trades that close
  **after it was first opened in this NinjaTrader session**, or after the last
  Submit or Reset. Trades taken before are never offered or sent. The AddOn
  still watches every account, which it needs for copier followers, and drops
  unsubmitted trades after 24 hours.
- **The status line** covers only the current trade: waiting for entry, in
  trade (direction, size, time open), trade closed and ready to submit, then
  "Sending..." and "Sent.".

### Chart images

- **Exit image**: taken about 1 second after the position goes flat.
  **Recapture** on the form replaces it, for example after you mark up the
  chart.
- **Entry image**: taken about 1 second after the entry fill, **only when
  "Entry image" is ticked** in Settings. There's no Recapture for it. The
  journal shows it behind an "Entry Image" link under the exit image.
- **Which chart.** The picture comes from a chart whose **visible** tab shows
  the trade's instrument. NinjaTrader can't capture a background tab. A chart
  whose Chart Trader account is the trade's account is preferred, and the
  copier dashboard chart is skipped. If no chart shows the instrument, there's
  no image (the status line says so, and Recapture can fix it). A missing
  image never blocks Submit.
- **Where they're saved**: `{Data folder}\images\{trade_id}.png` and
  `{trade_id}-entry.png`. They're kept after the trade is sent.

### Stop price

The AddOn records the stop you were actually risking, read-only (it never
places, moves or cancels orders):

- **What counts.** Your ATM's stop is recorded while it's on the **losing
  side** of your average entry. The ATM places one stop per target (Stop1,
  Stop2) at the same price. If you tighten or loosen either one while it's
  still at risk, that move is the new stop.
- **What doesn't.** Once the stop moves to breakeven or into profit (after
  Target 1, a trail, or by hand), it stops updating. Example: long 7700, ATM
  stop 7696, loosened to 7694, later moved to breakeven: the trade records
  7694.
- **Sent with the trade.** It's sent as `stop_price` and shown in the trade
  page's **STOP PRICE** cell. Click it to correct or add it (Enter saves,
  Escape cancels, blank clears).
- **No stop at risk:** nothing is sent.
- **Followers** record their own stop order when the copier is in Orders
  mode. In Executions mode (fills only, no stop orders) they take the
  master's stop price.

### Copier followers

When the Affordable Indicators copier is on a chart, Submit also sends each
follower account's **own** trade (its own instrument, entries, exits, legs and
P&L), carrying the master's note, trade type and images. The master is the
Chart Trader account the form follows. Followers are read from the copier's
own setup and matched to the master trade by market (ES/MES, NQ/MNQ, …),
direction (opposite for a fade) and entry time. If the copier didn't copy a
trade, only the master is sent.

Each follower also names its master trade (`copier_master_trade_id`), so the
journal links them:

- **The list** shows the master with a **Followers (N)** link. It expands to
  the follower trades, indented under it, and each one opens like any trade.
- **The trade page** labels the account **Master** or **Follower**.
- **Totals** (the summary strip, each day's header, the Overview and
  Statistics) add every trade of the selected accounts, followers included.
  To see one account on its own, select only that account.
- **Expanding a master** always lists all of its followers, even ones whose
  account isn't selected, so any of them can be opened. Those unselected
  followers aren't in the totals.
- **Filtering to a follower account** shows its trades as normal rows. With
  several accounts selected, a follower is listed on its own unless its
  master's account is also selected.

### Sending, retries and stuck trades

- **Two holding areas**, both saved in `{Data folder}\state.json` so nothing is
  lost if NinjaTrader closes or crashes:
  1. **Staged trades** are closed trades you haven't submitted yet. That's
     what Submit picks up.
  2. **The delivery queue** holds submitted trades the journal hasn't accepted
     yet.
- **Automatic retries.** If the journal can't be reached (timeout, connection
  error, server error), the AddOn tries **3 times in all**: about 5s, then 10s
  apart. The status line shows each try with a countdown: "Sending (try 1/3)…",
  "Try 1/3 failed: the journal can't be reached. Try 2/3 in 4s…", and so on.
- **You can keep trading.** Submit clears the form even if sending fails, so
  the next trade can be taken and submitted normally. Reset never touches
  submitted trades; a failed one stays in `state.json` until Retry sends it.
- **After 3 failed tries**, the trade is **Failed**: "N trades not sent… Click
  **Retry**". Retry resends **every** failed trade, from any chart or earlier
  Submit, each with 3 fresh tries. Any chart's form shows the count and the
  Retry button; during a trade it's a short note on the status line.
- **Failed trades never expire.** They keep their note, type and images until
  sent, across restarts. Nothing retries them automatically after a restart;
  click Retry on any form.
- **A bad token** (401/403) stops all sending until the token is fixed in
  Settings and Retry is clicked.
- **There's no "discard"** for a trade the journal refuses for good (422). It
  would stay in the failed count.

### Files and logs

| What | Where |
| --- | --- |
| Settings (token encrypted) | `%USERPROFILE%\ChartJot\settings.json` |
| State: open/staged trades, queue, forms | `{Data folder}\state.json` |
| Chart images | `{Data folder}\images\` |
| Log (`TRADE`, `CAPTURE`, `SUBMIT`, `COPIER`, `DELIVERY` lines) | `%LOCALAPPDATA%\ChartJot\nt8\chartjot-YYYYMMDD.log` |

Note text is never written to the log.

### Notes

- **Playback.** Trades on NinjaTrader's Playback connection come from its
  built-in **Playback101** account. The journal creates that account
  automatically (as a Sim account) the first time one arrives.
- **Sim accounts.** Account names starting with `Sim` or `Playback` are filed
  as Sim accounts in the journal.

### AddOn tests

```bash
dotnet test addon/tests
```

The AddOn's payloads are shared with the Laravel suite as fixtures in
`tests/Fixtures/addon/`, checked by `tests/Feature/Api/AddonContractTest.php`.
After changing the payload, regenerate them with
`PATS_WRITE_FIXTURES=1 dotnet test addon/tests`.

## Testing

```bash
php artisan test
```
