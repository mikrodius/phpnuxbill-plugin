# Mikrodius for PHPNuxBill

This plugin lets PHPNuxBill sell plans and vouchers while Mikrodius handles RADIUS: PPPoE
and hotspot logins, speed, expiry and data limits, and accounting. PHPNuxBill keeps billing,
invoices, payments and voucher generation. The plugin copies each paid plan or voucher to
Mikrodius and keeps it in sync.

## Requirements

Tested combinations. Other versions may work but have not been tested:

| Component | Tested |
| --- | --- |
| PHPNuxBill | 2025.3.20 (revision `d3e0596`) |
| PHP | 8.2 with `pdo_mysql`, `curl`, `openssl` and `mbstring` |
| MySQL | 8.0 |
| RouterOS, for router setup from PHPNuxBill | 7.23 (WireGuard) and 6.49.10 (L2TP, SSTP) |

You also need:
- A Mikrodius account and an API key with **read_write** access. No account yet?
  [Create a key](https://console.mikrodius.com/developer?new=key&name=PHPNuxBill&ref=phpnuxbill):
  it signs you up (new accounts get Rp1.000.000 free credit for 30 days) and opens the key
  dialog ready for PHPNuxBill. The plugin can create the workspace for you.
- PHPNuxBill's cron job running every 5 minutes. The plugin does its remote work from cron.

## Installation

1. Back up the PHPNuxBill database.
2. Install the plugin in one of these ways. Plugin Manager needs PHP's `zip` extension.
   - **From the plugin list**: open **Settings › Plugin Manager**, find **Mikrodius | Cloud
     RADIUS** and click **Install**.
   - **From GitHub**: in **Settings › Plugin Manager**, paste
     `https://github.com/mikrodius/phpnuxbill-plugin` into the GitHub URL field and install.
   - **From a ZIP**: download `mikrodius-phpnuxbill-<version>.zip` from the
     [latest release](https://github.com/mikrodius/phpnuxbill-plugin/releases/latest) and
     upload it in **Settings › Plugin Manager**.
   - **By hand**: copy the release ZIP's `plugin/` folder into `system/plugin/` and its
     `device/` folder into `system/devices/`.

   The plugin installs its device driver, `system/devices/MikrodiusRadius.php`, by itself on
   the next page view, so `system/devices/` must be writable by the web server (or copy the
   file there by hand from `system/plugin/mikrodius/device/`).
3. Check that the web server can write `system/plugin/mikrodius/`. On first use the plugin
   creates `system/plugin/mikrodius/private/key.php` (mode 0600, folder 0700). That file holds
   the key that encrypts the stored API key, webhook secrets and queued jobs.
4. Make sure PHPNuxBill's cron runs. **Settings › General** shows the exact line, for example:

   ```
   */5 * * * * cd /var/www/phpnuxbill/system && php cron.php
   ```

5. Open **Network › Mikrodius**. Only SuperAdmin and Admin accounts see this menu or can open its pages.

## Upgrading

Install the new version the same way: **Install** again in the plugin list, the GitHub URL,
or the new release ZIP. The installer overwrites the plugin's files, the plugin updates its
device driver, and everything else is kept:
- `private/key.php`
- the installation ID
- settings and mappings
- queued jobs and webhook deliveries

Database changes are applied automatically on the next page view or cron run.

Before upgrading, back up the database **and** `system/plugin/mikrodius/private/key.php`.
The two belong together: without the key, the stored API key, secrets and queued jobs
cannot be read. The plugin never replaces a lost key silently. If the key is lost, restore
it from backup, or save the API key and webhook secret again. Jobs queued before the loss
cannot be recovered.

## First setup

1. Open **Network › Mikrodius**, paste your API key, and click **Connect Mikrodius**.
   The billing timezone comes from PHPNuxBill. The saved key is encrypted; only its last
   four characters are shown. Custom API addresses and timezone overrides are under
   **Tools › Advanced settings**.
2. Select an existing workspace or create one by name. Mikrodius chooses an available node
   for a new workspace. The key must permit creation; a key scoped to existing workspaces
   may only select those workspaces. Normal workspace and NAS pricing applies.
3. The plugin inspects each enabled, unlinked PHPNuxBill router and prepares its NAS before
   installation. RouterOS 7 uses WireGuard; supported RouterOS 6 uses SSTP. Existing NAS
   links are reused, and mappings in other workspaces are kept. This step does not change
   router settings, billing plans, or customer access.
4. Expand **Workspace details & routers**, then choose **Install** for each prepared router. Review its PPP
   and hotspot services before applying changes. For an unreachable router, expand
   **Manual setup / existing NAS**, choose its connection, prepare the NAS, then get the
   install command from its installation page. Newly added routers can be prepared with
   **Prepare new routers**. Interrupted or uncertain creation asks you to link the existing
   NAS before retrying; refreshing never creates another NAS automatically.
5. **Plans**: in PHPNuxBill, set the device of every plan Mikrodius should handle to
   **MikrodiusRadius**.
   The plugin infers the plan's workspace from its router and creates its Mikrodius plan.
   To link an existing remote plan instead, use **Tools › Mappings**. A mapping's
   workspace cannot be changed later.
6. **Vouchers**: choose each plan's voucher mode **before** printing codes.

The default page focuses on connection status, API key and workspace selection. Leave the
key field blank to keep and check the saved key. Workspace statistics and router installation
status are collapsed under **Workspace details & routers**; other pages are under **Tools**.
Existing installations with one mapped workspace select it automatically. Workspace details
use the public API through the plugin's server; the browser never receives the saved API key
and needs no separate Mikrodius login. A statistics error does not invalidate a working key.
7. **Webhooks** (optional; cron reads the same state without them):
   - In the Mikrodius dashboard, add a webhook endpoint with the URL shown on this page.
   - Paste its signing secret here.
   - The plugin's API key does not need webhook permissions.

## Connecting routers

Every router needs a Mikrodius NAS before its users can log in. There are two ways:

- **Install from PHPNuxBill**: on **NAS & routers**, *Install* opens a step-by-step installer.
  It connects to the router through the login stored in **Network › Routers**, so that login
  needs the `api`, `read`, `write`, `test` and `sensitive` policies and PHPNuxBill must reach
  the router's API port. The steps:
  - It reads the router and lets you choose what to hand over to Mikrodius: PPP (PPPoE)
    and/or selected hotspot profiles.
  - It previews every setting it will change.
  - It creates the NAS, or links an existing one.
  - It applies and verifies each step separately, recording each step's state as it goes.

  If anything fails, *Resume* checks the router again first and never generates new NAS
  credentials. The installer adds only resources it tags as its own (the tunnel, the RADIUS
  entry and one firewall rule for RADIUS disconnect and speed-change requests). It does not
  replace hotspot servers, login pages, address pools or scripts, does not delete local
  users, and stops rather than quietly switching a router that already uses another RADIUS
  server. RouterOS 7 routers connect over WireGuard; RouterOS 6.49.10 and later over L2TP or
  SSTP. A
  successful install shows the router is configured; only a real login proves a subscriber
  can authenticate.
- **Install command**: for routers PHPNuxBill cannot reach, create the NAS in the Mikrodius
  dashboard, link it to the router on **Mappings**, then get the install command from the
  router's installer page (*Full install (fallback)*) and paste it into the router's terminal. This full
  install configures PPP and every active hotspot profile.

The router shortcut on PHPNuxBill's router pages appears only when no other plugin has
already claimed those pages' hooks. **NAS & routers** always works.

## Vouchers

PHPNuxBill still generates and sells the codes. Each plan has one of two modes:

- **Direct login** (default): the code is the username and password.
  - Minute, hour and day plans start counting at the first login.
  - Month and fixed-date plans need an exact expiry per code, which you set on the Vouchers
    page before the code syncs. Calendar months are never approximated as seconds.
- **Customer credit**: the code stays in PHPNuxBill until a customer redeems it into their own
  account. The customer's login then gets the recharge, with the expiry PHPNuxBill computed.

Each code keeps the mode it had when it first synced. Changing a plan's mode affects only
codes that have not synced yet.

Stock PHPNuxBill cannot be told which redemption path a code is meant for. A direct-login
code redeemed into an account, or a credit code used directly, may already be saved as a
billing record in PHPNuxBill before the plugin sees the conflict. The plugin then refuses to
grant that access in Mikrodius and shows a failed job. It does not undo the billing record.
Choosing modes before printing codes avoids this; the plugin cannot prevent it in every case.

## Day-to-day

- **Sync jobs**: every change PHPNuxBill makes becomes a job that cron sends to Mikrodius, in
  order, per customer or voucher.
  - A purchase that has not reached Mikrodius yet is marked **paid, provisioning pending**:
    the payment is saved, only the RADIUS access is waiting. Nobody needs to pay again.
  - *Retry sync* re-sends a failed job. It never charges, renews or changes billing.
  - Each failure shows what to fix, the error code and the Mikrodius request ID (quote it
    when contacting support).
  - Rows marked *diagnostic* clear by themselves on the next cron run once the cause is fixed.
- **Subscribers**:
  - Shows each synced customer plan or voucher with its access state, expiry and last sync.
  - *Refresh* reads the current state from Mikrodius.
  - A customer can have PPPoE and hotspot plans that share one login. The login type is
    changed explicitly, with a confirmation tick.
- **Webhooks**:
  - Shows received deliveries and whether they were processed.
  - When rotating the signing secret, the old one can stay valid for up to 24 hours.

PHPNuxBill runs only the first plugin registered for each hook. If another plugin claims the
purchase hook first, cron still finds every saved purchase within one run, so nothing is
lost, only delayed.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Pages say *Save a Mikrodius API key* | Settings: save a key. |
| *The API key is read-only* | Create a read_write key scoped to the workspace and save it. |
| *Mikrodius could not be reached* | The billing server's outbound HTTPS and DNS. Jobs retry by themselves. |
| Jobs stay queued | PHPNuxBill's cron is not running. Check the crontab line from Settings › General. |
| *Map the plan, or its router, to a workspace first* | Mappings: map the router or the plan to a workspace. |
| *Belongs to another billing system or customer* | A Mikrodius subscriber with the same username already exists. Rename one, then *Retry sync*. |
| *The form expired or came from another site* | Reload the page and try again. Each sign-in has its own form token. |
| Router install: *needs the api, read, write, test and sensitive policies* | Give the router login those policies in RouterOS (System › Users › Groups). |
| Router install: *did not answer* | The router's API port (8728 by default), the address in Network › Routers, and the router firewall. |
| Router install: *Another RADIUS server answers these services first* | Tick the cutover to disable those RADIUS entries (for all of their services, not only the ones you chose), or leave the router as it is. |
| Router install: *not known whether the NAS was created* | In the installer, *Link* the NAS it created (check the Mikrodius dashboard), or confirm *Create again*. |
| Deliveries rejected | Paste the endpoint's current signing secret on the Webhooks page. |
| *The MikrodiusRadius device could not be installed* | Make `system/devices/` writable by the web server and reload, or copy `system/plugin/mikrodius/device/MikrodiusRadius.php` into `system/devices/`. |
| Plugin Manager shows `rmdir(...installer/...)` warnings | PHPNuxBill's installer cleanup removes nested folders twice. The plugin is installed; check that **Network › Mikrodius** opens. |

Errors from the plugin are written to PHP's error log without secrets: API keys, passwords,
NAS credentials and webhook secrets are never shown on pages or written to logs.

## Removing the plugin

Change any plan that uses the MikrodiusRadius device first. Then delete
`system/plugin/mikrodius.php`, the `system/plugin/mikrodius/` folder, the
`system/plugin/ui/mikrodius_*.tpl` files and `system/devices/MikrodiusRadius.php`.

**Delete** in Plugin Manager's list removes the plugin's files but leaves
`system/devices/MikrodiusRadius.php` and `system/plugin/mikrodius/private/`; delete those by
hand. A driver left behind shows as *The Mikrodius plugin is not installed* under
**Settings › Devices**.

The plugin's `mikrodius_*` database tables are left in place. Routers and subscribers in
Mikrodius are not changed.

## License

Copyright (C) 2026 Mikrodius. This plugin is free software under the GNU General Public
License, version 3 (see `LICENSE`). The Mikrodius service it connects to is paid, with
pay-as-you-go pricing.
