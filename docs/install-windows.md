# Installing Chart Jot on Windows

NinjaTrader runs on Windows, and the journal runs on the same PC inside WSL
(Windows Subsystem for Linux), a small Linux system built into Windows. The
AddOn in NinjaTrader then sends your trades to `http://localhost:8000`, so they
never leave your PC.

This takes about 20 minutes the first time, plus a restart. You'll type
commands into two windows: **PowerShell** (Windows) to install WSL, then
**Ubuntu** (Linux) for the rest.

On a Mac, see [Installing on macOS](install-macos.md) instead.

## 1. Install WSL

WSL is built into Windows but switched off. Microsoft's guides are the
reference for setting it up; the steps below follow them and point to the
right page when something goes wrong.

### Before you start

- **Windows version:** Windows 11, or Windows 10 version 2004 (build 19041)
  or later. To check, press **Win+R**, type `winver` and press Enter. On an
  older Windows 10, update Windows first, or follow Microsoft's
  [manual install steps](https://learn.microsoft.com/en-us/windows/wsl/install-manual).
- **Virtualization turned on:** open **Task Manager** (Ctrl+Shift+Esc) →
  **Performance** → **CPU** and look for **Virtualization: Enabled** at the
  bottom right. If it says *Disabled*, turn it on in your PC's BIOS/UEFI
  settings first; Microsoft's
  [Enable virtualization on Windows](https://support.microsoft.com/windows/c5578302-6e43-4b4b-a449-8ced115f58e1)
  explains how, and your PC maker's support site has the exact keys.
- **An administrator account** on Windows, for the install command.

### Install

Microsoft's guide: **[Install WSL](https://learn.microsoft.com/en-us/windows/wsl/install)**.
The short version:

1. Right-click the **Start** button and choose **Terminal (Admin)** or
   **Windows PowerShell (Admin)**.
2. Run:

   ```powershell
   wsl --install
   ```

   This switches on WSL and the Virtual Machine Platform, installs the Linux
   kernel and installs **Ubuntu**.
3. Restart Windows when it's done.

### Create your Linux user

After the restart, **Ubuntu** opens by itself (or open it from the Start
menu) and finishes installing. It asks for a **username** and **password**:

- They're for Linux only and have nothing to do with your Windows account.
  Use a short lowercase username, like `trader`.
- **Nothing appears on screen while you type the password.** That's normal;
  type it and press Enter.
- You'll need this password for the `sudo` commands below.

Microsoft's guide:
[Set up your Linux username and password](https://learn.microsoft.com/en-us/windows/wsl/setup/environment#set-up-your-linux-username-and-password)
(including how to reset a forgotten one).

### Check that it worked

In PowerShell (not Ubuntu), run:

```powershell
wsl -l -v
```

You should see **Ubuntu** with **VERSION 2**.

### If something goes wrong

- **Error 0x80370102** (*"a required feature is not installed"*), or
  0x80070003: virtualization is off. See *Before you start*, then restart.
- **`wsl --install` only prints help text:** WSL is already installed, but no
  Linux distribution is. Run `wsl --install -d Ubuntu`.
- **The download sits at 0.0%:** run
  `wsl --install --web-download -d Ubuntu`.
- **Anything else:** Microsoft's
  [WSL troubleshooting guide](https://learn.microsoft.com/en-us/windows/wsl/troubleshooting#installation-issues)
  covers the installation errors one by one.

### Good to know

- **Windows Terminal** (built into Windows 11,
  [free from the Microsoft Store](https://learn.microsoft.com/en-us/windows/terminal/install)
  on Windows 10) makes Ubuntu easier to use: tabs, copy and paste, and
  Ubuntu in the drop-down next to PowerShell.
- **Keep the journal in Linux's own files** (your home folder, `~`), not on
  `C:`; it's much faster. Microsoft explains why under
  [File storage](https://learn.microsoft.com/en-us/windows/wsl/setup/environment#file-storage).
- **Windows doesn't update Ubuntu for you.** Now and then, run
  `sudo apt update && sudo apt upgrade` in Ubuntu.

From now on, open **Ubuntu** from the Start menu (or Windows Terminal)
whenever a step says to run a command.

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
