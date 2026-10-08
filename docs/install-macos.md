# Installing Chart Jot on macOS

NinjaTrader 8 only runs on Windows, so on a Mac it runs in a Windows virtual
machine (for example Parallels Desktop or VMware Fusion). The journal runs on
the Mac itself, and Windows in the virtual machine forwards
`http://localhost:8000` to it. The AddOn then sends to `localhost`, like it
does on a Windows PC.

This takes about 20 minutes the first time. You'll use the Mac's **Terminal**
(in Applications → Utilities) for most of it, and **PowerShell** in Windows
once.

If NinjaTrader runs on a separate Windows PC rather than in a virtual machine,
install the journal on that PC instead; see [Installing on
Windows](install-windows.md).

## 1. Install the tools

In Terminal, run each of these.

**Git** (macOS asks to install the Command Line Tools the first time; click
**Install** and run it again afterwards):

```bash
git --version
```

**PHP and Composer** (the language the journal is written in, and its package
installer):

```bash
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Quit Terminal and open it again, then check that both work:

```bash
php -v
composer -V
```

`php -v` should show 8.3 or newer.

**Node.js** (used once during setup to build the pages): download the **LTS**
installer from [nodejs.org](https://nodejs.org/) and run it. Then check:

```bash
node -v
```

`node -v` should show 22 or newer.

## 2. Download and set up the journal

```bash
cd ~
git clone https://github.com/rbruhn/chartjot.git
cd chartjot
composer setup
```

`composer setup` installs everything, creates the settings file (`.env`) and
the database, and builds the pages.

## 3. Set a password

Windows in the virtual machine reaches the journal over the network, so the
journal has to listen on the network too, and other devices on your Wi-Fi
could reach it as well. Set a password:

```bash
nano .env
```

- `CHARTJOT_PASSWORD=`: put a password after the `=`. Each browser asks for
  it once.
- `CHARTJOT_OWNER_NAME=Trader`: the name in the journal's title
  ("Trader's Trade Journal"). You can also change it later on the Profile page.

Save with **Ctrl+O**, **Enter**, then exit with **Ctrl+X**.

## 4. Start the journal

The journal needs two things running: the web server and the queue worker
(which handles CSV imports). Open two Terminal windows.

In the first:

```bash
cd ~/chartjot
php artisan serve --host=0.0.0.0 --port=8000
```

In the second:

```bash
cd ~/chartjot
php artisan queue:work
```

Leave both open while you trade; closing them stops the journal. If macOS asks
whether to accept incoming network connections for `php`, click **Allow**.

Open **http://localhost:8000** in your Mac's browser and enter the password.
The first visit creates your journal.

## 5. Forward the journal into Windows

**Find the Mac's address.** In Terminal on the Mac:

```bash
ipconfig getifaddr en0
```

It prints something like `192.168.1.20`. If it prints nothing, try `en1`
instead of `en0`.

**Check that Windows can reach it.** In the Windows virtual machine, open a
browser at `http://192.168.1.20:8000` (with your address). The journal's
unlock page should appear.

**Forward localhost to it.** In Windows, right-click **Start** → **Terminal
(Admin)** or **Windows PowerShell (Admin)**, and run (with your address):

```powershell
netsh interface portproxy add v4tov4 listenaddress=127.0.0.1 listenport=8000 connectaddress=192.168.1.20 connectport=8000
```

Now `http://localhost:8000` in Windows opens the journal too. Windows keeps this
setting after a restart.

If the Mac's address changes (for example on another Wi-Fi network), remove
the old forward and add it again with the new address:

```powershell
netsh interface portproxy delete v4tov4 listenaddress=127.0.0.1 listenport=8000
```

## 6. Get the journal ready for NinjaTrader

1. In the journal, open your name (top right) → **Journal Settings**.
2. Set your **time zone** and save. The journal refuses trades until it's set.
3. Under **NinjaTrader intake**, click **Generate new token** and copy it
   somewhere for a moment; it's shown only once.
4. The intake URL to use in the AddOn is
   `http://localhost:8000/api/v1/trades`.

## 7. Install the AddOn

In the Windows virtual machine, follow [Installing or
updating](addon.md#installing-or-updating) in the AddOn guide, then
[First-time setup](addon.md#first-time-setup): paste the intake URL and the
token into the AddOn's **Settings**.

Your accounts appear in the journal on their first trade; see
[Notes](addon.md#notes).

## Every day

Before you trade, start the journal in its two Terminal windows (step 4).

If you submit a trade while the journal isn't running, the AddOn tries three
times and then marks it **Failed**. Nothing is lost: start the journal and
click **Retry** on the Chart Jot form.

## Updating

Stop the journal (**Ctrl+C** in both windows), then:

```bash
cd ~/chartjot
git pull
composer install
php artisan migrate --force
npm install
npm run build
```

Start it again as in step 4. The AddOn is updated separately; see
[Installing or updating](addon.md#installing-or-updating).

## Backing up

Your whole journal is in two places inside `~/chartjot`:

- `database/database.sqlite`: trades, notes, accounts and settings
- `storage/app/`: chart images and uploads

Stop the journal first, then copy both somewhere safe. To open the folder in
Finder:

```bash
open ~/chartjot
```
