# Installation

[README](../README.md) · **Install** · [Usage](USAGE.md) · [Agents](../AGENTS.md) · [Development](DEVELOPMENT.md)

## Requirements

- Unraid **7.x** (tested: 7.1.2 – see [Limitations](../README.md#limitations))
- **Community Applications** plugin installed. The app feed is downloaded automatically when
  needed (also after a reboot); opening the *Apps* tab is not required.
- Internet access from the server (for the app feed and image pulls)
- Docker service running
- Shell access (SSH or the WebUI terminal) as root

## Install the plugin

### From GitHub (once the repository is public)

WebUI: **Plugins → Install Plugin**, paste

```
https://github.com/tommibembel/appctl-for-unraid/releases/latest/download/appctl.plg
```

and click *Install*. Or on the command line:

```
plugin install https://github.com/tommibembel/appctl-for-unraid/releases/latest/download/appctl.plg
```

### From a local file

Download or build `appctl.plg` (see below), copy it to the server and run:

```
plugin install /tmp/appctl.plg
```

This is the same mechanism the WebUI uses; Unraid copies the file to `/boot/config/plugins/`.

### Check

```
appctl version
```

## What gets installed

| Path | Content |
|---|---|
| `/boot/config/plugins/appctl.plg` | the plugin file (on the flash drive; Unraid re-installs from it at every boot) |
| `/usr/local/emhttp/plugins/appctl/` | `appctl.php`, `webui-action.php`, `appctl` (wrapper) – in RAM |
| `/usr/local/bin/appctl` | symlink to the wrapper |

Nothing else is changed. The plugin has no settings and no WebUI page; it survives reboots
because Unraid re-installs all plugins in `/boot/config/plugins/` at boot.

## Update

- WebUI: **Plugins** → *Check for Updates* → *Update* (needs the public GitHub release).
- Command line: `plugin update appctl.plg`
- From a newer local file: `plugin install /tmp/appctl.plg` again.

## Remove

WebUI: **Plugins** → *appctl* → *Remove*, or:

```
plugin remove appctl.plg
```

This removes the program files and the command; Unraid moves the plugin file to
`/boot/config/plugins-removed/`.
Containers and templates installed with `appctl` are **not** touched – they are normal
Unraid containers and stay manageable in the WebUI.

## Build from source

```
git clone https://github.com/tommibembel/appctl-for-unraid.git
cd appctl-for-unraid
./build.sh                 # version = today, YYYY.MM.DD
./build.sh 2026.11.16b     # explicit version
```

Result: `dist/appctl.plg`. The program files from `src/appctl/` are embedded in the
`.plg`; there is no separate package to download. `build.sh` needs only bash and sed
(`xmllint`, if present, validates the result).

## Releases (maintainers)

The GitHub Action [`.github/workflows/build.yml`](../.github/workflows/build.yml) runs on every push
and pull request: PHP syntax check, build, and a check that the embedded files come out of the
`.plg` unchanged. The built `.plg` is attached to the run as artifact.

To publish a version:

1. Add a section `###YYYY.MM.DD` at the top of [`plugin/CHANGES.md`](../plugin/CHANGES.md).
2. If tested on a new Unraid / CA version, update the tested versions in the README.
3. Tag and push: `git tag 2026.11.16 && git push origin 2026.11.16`.

The Action then creates a GitHub release with `appctl.plg`. The plugin's `pluginURL` points
to the latest release, so the Unraid *Plugins* tab offers the update automatically.
