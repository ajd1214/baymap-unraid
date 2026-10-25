# Baymap for Unraid

A map of your drive bays with health at a glance, disk records, and on-demand Locate and Benchmark.

**It never wakes a sleeping disk.** Everything on the page comes from data Unraid already keeps:
`/var/local/emhttp/disks.ini`, `devs.ini` (unassigned devices) and the SMART report Unraid saves in
`/var/local/emhttp/smart/` each time a disk is awake. Sleeping disks show their last known values with
the time of that report. There is no background job and no schedule.

> **Status: early release.**

## Features

- **Map:** bay groups (cages, chassis, rows) in a grid; trays numbered along rows or down columns.
  Each tray shows the disk, model, size, active/asleep, temperature, power-on time, SSD life used and a
  health badge (OK, INFO, WARN, FAIL). A tray whose disk is not connected says so.
- **Health:** failed SMART attributes, reallocated/pending/uncorrectable sectors, CRC errors (cable),
  NVMe critical warnings and media errors, SSD wear, Unraid read/write errors and disk status.
- **Disk records:** purchase date, warranty end, manufacture date, notes. Kept by serial number, so a
  record follows the disk to another bay.
- **Locate:** reads from one disk for 30 seconds so its activity light blinks. **Benchmark:** `hdparm -t`
  read speed, history kept. Both run only when clicked, after a warning, and wake only that disk.
- **Import from Disk Location:** groups, tray placements and disk records. Runs automatically on first
  install if Disk Location's settings are still on the flash drive, and from the Layout tab on request.

## Install

From the Unraid web UI: **Plugins → Install Plugin**, paste the `baymap.plg` URL, **Install**.

While the repository is private, copy `baymap.plg` and `packages/baymap-<version>.txz` to the server, then:

```bash
mkdir -p /boot/config/plugins/baymap
cp baymap-*.txz /boot/config/plugins/baymap/
plugin install /path/to/baymap.plg
```

The page is **Utilities → Baymap** (tabs: Map, Layout).

## Files

- `/boot/config/plugins/baymap/layout.json`: groups and which serial sits in which tray
- `/boot/config/plugins/baymap/records.json`: disk records and benchmark history

Removing the plugin keeps both files.

## Building

`./build.sh` packs `src/` into `packages/baymap-<version>.txz` and writes its SHA256 into `baymap.plg`.
Bump the version entity in `baymap.plg` first.

## Credits

Inspired by [Disk Location](https://github.com/olehj/disklocation) by **olehj**, which pioneered the
bay map for Unraid. Baymap is an independent rewrite (no Disk Location code) built around one rule:
never send a command to a sleeping disk.

## License

MIT. See [LICENSE](LICENSE).
