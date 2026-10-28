# Mikrodius for PHPNuxBill

This plugin lets PHPNuxBill sell plans and vouchers while Mikrodius handles RADIUS: PPPoE
and hotspot logins, speed, expiry and data limits, and accounting. PHPNuxBill keeps billing,
invoices, payments and voucher generation. The plugin copies each paid plan or voucher to
Mikrodius and keeps it in sync.

Open **Network › Mikrodius** to use the embedded interface. Connection, router
installation, Statistics and Tools update without reloading PHPNuxBill.
The interface follows PHPNuxBill's light or dark theme. Long pages scroll inside the
frame, and navigation remembers their scroll positions. The release already includes the
compiled interface; no Node.js server or frontend build is needed on your installation.

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
- Outbound HTTPS access from PHPNuxBill. Normal saved actions synchronize immediately; scheduled recovery handles missed or interrupted work.

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
4. Keep PHPNuxBill's ordinary billing cron for its scheduled billing tasks. **Settings › General** shows the exact line, for example:

   ```
   */5 * * * * cd /var/www/phpnuxbill/system && php cron.php
   ```

   Separately, schedule plugin-only recovery every minute (adjust the installation path):

   ```
   * * * * * php /var/www/phpnuxbill/system/plugin/mikrodius/recover.php
   ```

   Recovery has one 30-second budget and an installation overlap lock. It does not run
   billing cron or generate purchases. Normal actions work with recovery disabled.

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
   **Tools › Settings**.
2. Select an existing workspace or create one by name. Mikrodius chooses an available node
   for a new workspace. The key must permit creation; a key scoped to existing workspaces
   may only select those workspaces. Normal workspace and NAS pricing applies.
3. The plugin inspects each enabled, unlinked PHPNuxBill router and prepares its NAS before
   installation. RouterOS 7 uses WireGuard; supported RouterOS 6 uses SSTP. Existing NAS
   links are reused, and mappings in other workspaces are kept. This step does not change
   router settings, billing plans, or customer access.
4. Under **Your routers**, choose **Install** for each prepared router. Review its PPP
   and hotspot services before applying changes. For an unreachable router, expand
   **Manual setup / existing NAS**, choose its connection, prepare the NAS, then get the
   install command from its installation page. Newly added routers can be prepared with
   **Prepare new routers**. Interrupted or uncertain creation asks you to link the existing
   NAS before retrying; refreshing never creates another NAS automatically.
5. **Plans**: in PHPNuxBill, set the device of every plan Mikrodius should handle to
   **MikrodiusRadius**.
   The plugin infers the plan's workspace from its router and creates its Mikrodius plan.
   Existing plan links are preserved. A plan's workspace cannot be changed later.
6. **Vouchers**: choose each plan's voucher mode **before** printing codes.

The default page focuses on connection status, API key and workspace selection. Leave the
key field blank to keep and check the saved key. Workspace statistics and router installation
status appear under **Your routers**; synchronization jobs, webhooks and settings are under **Tools**.
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
  dashboard, link it through **Manual setup / existing NAS** on the connection page, then get the install command from the
  router's installer page (*Full install (fallback)*) and paste it into the router's terminal. This full
  install configures PPP and every active hotspot profile.

The router shortcut on PHPNuxBill's router pages appears only when no other plugin has
already claimed those pages' hooks. **NAS & routers** always works.

## Vouchers

PHPNuxBill still generates and sells the codes. Each plan has one of two modes:

- **Direct login** (default): the code is the username and password.
  - Minute, hour and day plans start counting at the first login.
  - Month and fixed-date plans need an exact expiry per code, which you set under Tools › Settings before the code syncs. Calendar months are never approximated as seconds.
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

- Normal actions save billing first, then synchronize affected records and their dependencies
  within eight seconds, including API calls and locks. Feedback distinguishes saved, synced,
  pending and failed. A large voucher batch may remain partly pending.
- **Tools › Sync jobs** shows durable work and actionable errors. **Retry sync** immediately
  attempts eligible work without creating payments; scheduled retry times and Retry-After
  remain in force. A failed or uncertain predecessor must resolve before later work.
- **Tools › Settings** retains plan voucher modes, eligible unsynchronized calendar voucher
  exact expiry, and mapped-customer login-type overrides. Issued voucher semantics are preserved.
- **Webhooks** commits valid deliveries before acknowledgement and attempts processing immediately.
  Accepted events return 202 even when processing remains pending. Rotation supports up to
  24 hours of overlap. Recovery handles missed deliveries and interrupted processing.
- Native request observation also captures saved changes when another plugin owns a hook.
  Plain customer creation without an entitlement does not grant cloud access.

## Statistics

Statistics reads the selected workspace's real cloud subscribers, including those created
directly in Mikrodius. Viewing them never imports customers or billing records. Search uses
the public API's username/external-ID search, with 50-row cursor pages and visible-page
refresh every 30 seconds. Offline describes connection; an offline account can still be Allowed.

Choose 7, 30 or 90 days (30 by default). Upload/download totals and charts use real accounting
usage; 7/30 days use daily groups, 90 days use monthly groups in the workspace timezone.
Totals cover **Current workspace subscribers**, independently of table search and pagination.
The server enumerates subscribers and reads their usage in bounded resumable chunks. It shows
totals only after all reads complete, with snapshot timestamps and stale/error feedback.
Deleted subscribers and accounting under previous usernames are excluded. Enumeration is
not an atomic historical workspace snapshot; a deletion during compilation restarts it.

Live transfer rates are unavailable in the existing public API. The main overview's Traffic
today can count earlier traffic from sessions updated after midnight; selected-period usage
does not substitute that counter. Unavailable data stays unavailable, and empty charts contain
no invented activity. Keys remain on the server; cached responses exclude credentials.

Old Subscribers/Vouchers links redirect to Statistics. Their old write endpoints return410
and direct operators to Settings. Obsolete manual mapping links also direct to Settings;
router installation retains automatic ownership-aware mappings.

## Saved assignment mismatches

PHPNuxBill can save a new recharge plan without updating its saved router/login type when
switching services. Such a mismatch is reported as needing correction, and obsolete owned
access is suspended after the saved change. Correct the recharge or use a normal purchase
to assign the intended plan. Consistent manual assignments use the exact saved expiry with
create/update only; they do not invent a payment, renew, or reset usage.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Pages say *Save a Mikrodius API key* | Settings: save a key. |
| *The API key is read-only* | Create a read_write key scoped to the workspace and save it. |
| *Mikrodius could not be reached* | The billing server's outbound HTTPS and DNS. Jobs retry by themselves. |
| Jobs stay queued | Check the blocking job, mapping and retry time in Tools › Sync jobs; use Retry sync for eligible work and verify the dedicated recovery schedule. |
| *Map the plan, or its router, to a workspace first* | Prepare and install the plan's router in the selected workspace. The plan uses that router's workspace. |
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
