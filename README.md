# Chart Jot

A personal trading journal for NinjaTrader 8 traders. Trades are sent
automatically from a custom [NT8 AddOn](https://github.com/rbruhn/chartjot/releases/download/addon/ChartJot-AddOn.zip) via a REST API, or imported manually
from a CSV export of NT8's Trade Performance → Executions screen. Review
trades, add notes, attach screenshots, and track performance across accounts.

You run your own copy of the journal on your own computer. There's no
account to sign up for, and your trades stay on your machine.

## Install

- **[Windows](docs/install-windows.md)**: the journal runs on the same PC as
  NinjaTrader, in WSL.
- **[macOS](docs/install-macos.md)**: the journal runs on the Mac, and
  NinjaTrader in a Windows virtual machine.

The short version, if you already have PHP 8.3+, Composer and Node.js 22+:

```bash
git clone https://github.com/rbruhn/chartjot.git
cd chartjot
composer setup
composer dev
```

Then open http://localhost:8000.

## Guides

- **[The NinjaTrader AddOn](docs/addon.md)**: installing it, first-time
  setup, the form, chart images, stop price, copier followers, retries, files
  and logs.
- **[Development](docs/development.md)**: self-hosted and hosted modes,
  building and releasing the AddOn, tests.

## Get the AddOn

**[Download the current AddOn](https://github.com/rbruhn/chartjot/releases/download/addon/ChartJot-AddOn.zip)** (`ChartJot-AddOn.zip`). This link
always gets the latest version. Import it in NinjaTrader with **Tools → Import →
NinjaScript…**. See [Installing or updating](docs/addon.md#installing-or-updating).

The [Chart Jot NT8 AddOn](https://github.com/rbruhn/chartjot/releases/tag/addon)
release page has the same file named with its version, and the release notes.

## License

[MIT](LICENSE)
