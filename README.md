# Mikrodius for PHPNuxBill

<p>
  <a href="#english"><img src="https://img.shields.io/badge/English-0969da?style=for-the-badge" alt="English"></a>
  <a href="#bahasa-indonesia"><img src="https://img.shields.io/badge/Bahasa%20Indonesia-cf222e?style=for-the-badge" alt="Bahasa Indonesia"></a>
</p>

## English

PHPNuxBill keeps billing, invoices, payments and vouchers. Mikrodius handles RADIUS: PPPoE
and hotspot logins, speed, expiry, data limits and accounting. The plugin copies each paid
plan or voucher to Mikrodius and keeps them in sync. Plans keep the names you gave them in
PHPNuxBill.

### Requirements

- PHPNuxBill 2025.3.20, PHP 8.2 (`pdo_mysql`, `curl`, `openssl`, `mbstring`, and `zip` for
  Plugin Manager), MySQL 8.0
- RouterOS 7 (WireGuard) or RouterOS 6.49.10+ (L2TP or SSTP)
- A Mikrodius API key with **read_write** access.
  [Create one](https://console.mikrodius.com/developer?new=key&name=PHPNuxBill&ref=phpnuxbill):
  new accounts get Rp1.000.000 free credit for 30 days.

### Install

1. Back up the PHPNuxBill database.
2. In **Settings › Plugin Manager**, install **Mikrodius | Cloud RADIUS** from the list, or
   paste `https://github.com/mikrodius/phpnuxbill-plugin`, or upload the
   [latest release ZIP](https://github.com/mikrodius/phpnuxbill-plugin/releases/latest).
3. Make `system/plugin/mikrodius/` and `system/devices/` writable by the web server.
4. Add the recovery cron next to PHPNuxBill's own billing cron (adjust the path):

   ```
   * * * * * php /var/www/phpnuxbill/system/plugin/mikrodius/recover.php
   ```

### Set up

1. Open **Network › Mikrodius**, paste your API key and click **Connect Mikrodius**.
2. Pick a workspace or create one.
3. Under **Your routers**, click **Install** for each router. The router login needs the
   `api`, `read`, `write`, `test` and `sensitive` policies. For a router PHPNuxBill can't
   reach, use **Manual setup / existing NAS** and paste the install command into the router.
4. Set the device of every plan Mikrodius should handle to **MikrodiusRadius**.
5. Choose each plan's voucher mode in **Tools › Settings** before printing codes:
   - **Direct login**: the code is the username and password.
   - **Customer credit**: a customer redeems the code into their own account.
6. Optional: add a webhook in the Mikrodius dashboard and paste its signing secret.

Check sync status and retry failed jobs in **Tools › Sync jobs**.

### Upgrade

Install the new version the same way. Settings, mappings and queued jobs are kept. Back up the
database **and** `system/plugin/mikrodius/private/key.php` first: without that key the saved
API key and secrets can't be read.

### Troubleshooting

| Problem | Fix |
| --- | --- |
| *The API key is read-only* | Save a **read_write** key. |
| *Mikrodius could not be reached* | Check outbound HTTPS and DNS. Jobs retry by themselves. |
| Jobs stay queued | Open **Tools › Sync jobs**, fix the blocking job, check the recovery cron. |
| *Belongs to another billing system or customer* | The username already exists in Mikrodius. Rename one, then **Retry sync**. |
| Router *did not answer* | Check the API port (8728), the address in **Network › Routers** and the router firewall. |
| *MikrodiusRadius device could not be installed* | Make `system/devices/` writable, or copy `system/plugin/mikrodius/device/MikrodiusRadius.php` there. |

### Remove

Move plans off the MikrodiusRadius device, then delete the plugin in Plugin Manager. Also delete
`system/devices/MikrodiusRadius.php` and `system/plugin/mikrodius/private/` by hand. Routers and
subscribers in Mikrodius are not changed.

## Bahasa Indonesia

PHPNuxBill tetap mengurus billing, invoice, pembayaran dan voucher. Mikrodius mengurus
RADIUS: login PPPoE dan hotspot, speed, masa aktif, kuota dan accounting. Plugin ini
mengirim setiap paket atau voucher yang sudah dibayar ke Mikrodius dan menjaga datanya tetap
sama. Nama paket di Mikrodius sama persis dengan nama yang kamu buat di PHPNuxBill.

### Kebutuhan

- PHPNuxBill 2025.3.20, PHP 8.2 (`pdo_mysql`, `curl`, `openssl`, `mbstring`, dan `zip` untuk
  Plugin Manager), MySQL 8.0
- RouterOS 7 (WireGuard) atau RouterOS 6.49.10+ (L2TP atau SSTP)
- API key Mikrodius dengan akses **read_write**.
  [Buat di sini](https://console.mikrodius.com/developer?new=key&name=PHPNuxBill&ref=phpnuxbill):
  akun baru dapat kredit gratis Rp1.000.000 selama 30 hari.

### Instalasi

1. Backup dulu database PHPNuxBill.
2. Di **Settings › Plugin Manager**, install **Mikrodius | Cloud RADIUS** dari daftar, atau
   tempel `https://github.com/mikrodius/phpnuxbill-plugin`, atau upload
   [ZIP rilis terbaru](https://github.com/mikrodius/phpnuxbill-plugin/releases/latest).
3. Pastikan web server bisa menulis ke `system/plugin/mikrodius/` dan `system/devices/`.
4. Tambahkan cron recovery di samping cron billing bawaan PHPNuxBill (sesuaikan path-nya):

   ```
   * * * * * php /var/www/phpnuxbill/system/plugin/mikrodius/recover.php
   ```

### Setup

1. Buka **Network › Mikrodius**, tempel API key, lalu klik **Connect Mikrodius**.
2. Pilih workspace atau buat yang baru.
3. Di **Your routers**, klik **Install** untuk setiap router. Login router butuh policy
   `api`, `read`, `write`, `test` dan `sensitive`. Kalau router tidak bisa dijangkau
   PHPNuxBill, pakai **Manual setup / existing NAS** lalu tempel install command di terminal
   router.
4. Ubah device setiap paket yang mau diurus Mikrodius jadi **MikrodiusRadius**.
5. Tentukan mode voucher tiap paket di **Tools › Settings** sebelum mencetak kode:
   - **Direct login**: kodenya jadi username dan password.
   - **Customer credit**: pelanggan menukarkan kode ke akunnya sendiri.
6. Opsional: tambahkan webhook di dashboard Mikrodius dan tempel signing secret-nya.

Status sinkronisasi dan job yang gagal bisa dicek dan di-retry di **Tools › Sync jobs**.

### Upgrade

Install versi baru dengan cara yang sama. Setting, mapping dan job yang antre tetap aman.
Backup dulu database **dan** `system/plugin/mikrodius/private/key.php`: tanpa key itu, API key
dan secret yang tersimpan tidak bisa dibaca.

### Kalau ada masalah

| Masalah | Solusi |
| --- | --- |
| *The API key is read-only* | Simpan API key dengan akses **read_write**. |
| *Mikrodius could not be reached* | Cek koneksi HTTPS keluar dan DNS. Job akan di-retry otomatis. |
| Job tertahan di antrean | Buka **Tools › Sync jobs**, beresin job yang menghambat, cek cron recovery. |
| *Belongs to another billing system or customer* | Username itu sudah ada di Mikrodius. Ganti salah satu, lalu **Retry sync**. |
| Router *did not answer* | Cek port API (8728), alamat di **Network › Routers** dan firewall router. |
| *MikrodiusRadius device could not be installed* | Buat `system/devices/` bisa ditulis, atau salin `system/plugin/mikrodius/device/MikrodiusRadius.php` ke sana. |

### Hapus plugin

Pindahkan dulu paket dari device MikrodiusRadius, lalu hapus plugin di Plugin Manager. Hapus
juga `system/devices/MikrodiusRadius.php` dan `system/plugin/mikrodius/private/` secara manual.
Router dan subscriber di Mikrodius tidak berubah.

## License

Copyright (C) 2026 Mikrodius. Free software under the GNU General Public License, version 3
(see `LICENSE`). The Mikrodius service it connects to is paid, with pay-as-you-go pricing.
