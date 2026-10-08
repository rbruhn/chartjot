# Installing Chart Jot on Windows

NinjaTrader runs on Windows, and the journal runs on the same PC inside WSL
(Windows Subsystem for Linux), a small Linux system built into Windows. The
AddOn in NinjaTrader then sends your trades to `http://localhost:8000`, so they
never leave your PC.

This takes about 20 minutes the first time. You'll type commands into two
windows: **PowerShell** (Windows) once, then **Ubuntu** (Linux) for the rest.

On a Mac, see [Installing on macOS](install-macos.md) instead.

## 1. Install WSL

1. Right-click the **Start** button and choose **Terminal (Admin)** or
   **Windows PowerShell (Admin)**.
2. Run:

   ```powershell
   wsl --install
   ```

3. Restart Windows when it asks.
4. After the restart, an **Ubuntu** window opens and finishes installing. It
   asks for a username and password. These are for Linux only; pick anything
   you'll remember (the password is needed for `sudo` commands below).

From now on, open **Ubuntu** from the Start menu whenever a step says to run
a command.

## 2. Install the tools

In Ubuntu, run each of these.

**Basic tools:**

```bash
sudo apt update && sudo apt install -y git unzip curl
```

**PHP and Composer** (the language the journal is written in, and its package
installer):

```bash
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

Close the Ubuntu window and open a new one, then check that both work:

```bash
php -v
composer -V
```

`php -v` should show 8.3 or newer.

**Node.js** (used once during setup to build the pages):

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
```

Close and reopen Ubuntu again, then:

```bash
nvm install --lts
node -v
```

`node -v` should show 22 or newer.

## 3. Download and set up the journal

```bash
cd ~
git clone https://github.com/rbruhn/chartjot.git
cd chartjot
composer setup
```

`composer setup` installs everything, creates the settings file (`.env`) and
the database, and builds the pages. Keep the journal in your Linux home folder
(`~/chartjot`) as shown, not under `/mnt/c`; it's much faster there.

### Optional: your name and a password

The settings live in `.env`. To change them:

```bash
nano .env
```

- `CHARTJOT_OWNER_NAME=Trader`: the name in the journal's title
  ("Trader's Trade Journal"). You can also change it later on the Profile page.
- `CHARTJOT_PASSWORD=`: leave it empty for no password. The journal only
  listens on this PC, so other devices can't reach it anyway. Set one if other
  people use this PC, or if you later make the journal reachable from other
  devices.

Save with **Ctrl+O**, **Enter**, then exit with **Ctrl+X**.

## 4. Start the journal

```bash
cd ~/chartjot
composer dev
```

Leave this window open while you trade; closing it stops the journal. To stop
it yourself, press **Ctrl+C**.

Open **http://localhost:8000** in your browser (Edge, Chrome, …) on Windows.
The first visit creates your journal.

## 5. Get the journal ready for NinjaTrader

1. In the journal, open your name (top right) → **Journal Settings**.
2. Set your **time zone** and save. The journal refuses trades until it's set.
3. Under **NinjaTrader intake**, click **Generate new token** and copy
   it somewhere for a moment; it's shown only once.
4. Note the intake URL shown there: `http://localhost:8000/api/v1/trades`.

## 6. Install the AddOn

Follow [Installing or updating](addon.md#installing-or-updating) in the AddOn
guide, then [First-time setup](addon.md#first-time-setup): paste the intake
URL and the token into the AddOn's **Settings**.

Your accounts appear in the journal on their first trade; see
[Notes](addon.md#notes).

## Every day

Before you trade, open Ubuntu and start the journal:

```bash
cd ~/chartjot
composer dev
```

If you submit a trade while the journal isn't running, the AddOn tries three
times and then marks it **Failed**. Nothing is lost: start the journal and
click **Retry** on the Chart Jot form.

## Updating

Stop the journal (**Ctrl+C**), then:

```bash
cd ~/chartjot
git pull
composer install
php artisan migrate --force
npm install
npm run build
```

Start it again with `composer dev`. The AddOn is updated separately; see
[Installing or updating](addon.md#installing-or-updating).

## Backing up

Your whole journal is in two places inside `~/chartjot`:

- `database/database.sqlite`: trades, notes, accounts and settings
- `storage/app/`: chart images and uploads

Copy both somewhere safe. To open the folder in File Explorer:

```bash
cd ~/chartjot
explorer.exe .
```

Stop the journal first (**Ctrl+C**) so the database isn't being written while
you copy it.
