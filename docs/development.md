# Development

## Stack

- Laravel 13 · PHP 8.5
- Livewire 3 (Volt) · Alpine.js · Tailwind CSS 3.4
- Self-hosted: SQLite, with the queue and cache in the database
- Hosted: MySQL, Redis, and Laravel Horizon for the queue

## Self-hosted and hosted modes

`CHARTJOT_SELF_HOSTED` (in `config/chartjot.php`) switches between them.
`.env.example` is set up for self-hosting.

- **Self-hosted** (`true`): one trader and no login. Every browser request is
  signed in as the owner, who is created on the first visit. Registration,
  admin, friends and trade sharing return 404, and no email is sent.
  `CHARTJOT_PASSWORD`, if set, has each browser unlock the journal once.
- **Hosted** (`false`): the multi-user app with registration, admin approval,
  friends and sharing. Set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` and
  `LADA_CACHE_ACTIVE=true`, configure mail, and run Horizon.

The tests always run hosted (`phpunit.xml`); `tests/Feature/SelfHostedModeTest.php`
switches self-hosted mode on.

## The AddOn source

The companion NinjaTrader 8 AddOn lives in [`addon/`](../addon/) and posts
trades to this app's API. See [`NT8.md`](../NT8.md) for the AddOn
specification. Using it is covered in [addon.md](addon.md).

- `addon/core/`: plain C# (no NinjaTrader types). This is where trade tracking,
  payloads, the delivery queue, the form and follower rules live.
- `addon/nt8/ChartJot.cs`: the NinjaScript AddOn. It covers NT8 events, the
  chart form, settings, chart images and the background sender.
- `addon/nt8/ChartJot.NT8.csproj`: compiles both into **`ChartJot.dll`**, the
  one file NinjaTrader loads, the way vendors ship add-ons.
- `addon/tests/`: unit tests for `addon/core`.

## Building the package

`ChartJot.dll` compiles against NinjaTrader's own DLLs and the real .NET
Framework assemblies in `C:\Windows\Microsoft.NET`. Only a Windows PC with
NinjaTrader 8 has those, so the package is built there, from WSL, never on
GitHub:

```bash
addon/package.sh
```

This writes `addon/dist/ChartJot-AddOn-<version>.zip`, with the version from
`AddonVersion` in `ChartJot.cs`. Before zipping, it checks the DLL with
Windows' own .NET:

- **Framework references must be version 4.0.0.0.** NinjaScript compiles
  against those, and a newer `System.Net.Http` breaks every script's compile.
- **No `netstandard` reference.**
- **The DLL's version must match `AddonVersion`.**

**Releasing.** Merging to `main` creates the GitHub Release. When the AddOn
changed, the release notes say so. Then attach the package from the release's
commit:

```bash
git checkout <release-tag>
addon/release.sh <release-tag>
git checkout development
```

`release.sh` refuses unless the checkout is exactly the release's commit with
no local changes. It skips the upload if `addon/core` and `addon/nt8` didn't
change since the previous release (use `--force` to attach anyway).

Besides attaching the zip to the app release, it updates the fixed
[`addon` release](https://github.com/rbruhn/chartjot/releases/tag/addon):

- moves its tag to the release's commit;
- replaces the zip;
- rewrites its notes;
- keeps it from being marked "Latest".

App-only releases leave that page alone, so it always has the current AddOn.

**For development**, close NinjaTrader and copy
`addon/nt8/bin/package/ChartJot.dll` (left by `package.sh`) over the one in
`Documents\NinjaTrader 8\bin\Custom\`, then start NinjaTrader. It's locked
while NinjaTrader runs. Or import the zip.

## AddOn tests

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

## Running it while developing

```bash
composer dev
```

This runs the web server, the queue worker and Vite together.

## Moving a journal from the hosted site to a self-hosted copy

A one-time move for one trader (#106). On the hosted server:

```bash
php artisan journal:export friend@example.com
```

The user can be given by email or id. It writes
`storage/app/exports/journal-<id>-<date>.zip` (`--path=` for another folder)
with the accounts, deposits and withdrawals, trades with their executions,
legs, notes, stop prices and copier links, and the chart images. Friends'
comments, invitations and the intake token aren't included. Copy the zip off
the server (scp/SFTP) and give it to the trader.

On the self-hosted copy, after opening the journal once so the owner exists:

```bash
php artisan journal:import ~/journal-2-2026-10-08-152054.zip
```

It imports into the only user's journal (`--email=` picks one when there are
several), sets the time zone if the journal has none, and prints a summary.
Accounts with the same name are reused, and trades that already exist are
skipped, so running it twice is harmless.
