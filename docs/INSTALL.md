# Installation

[README](../README.md) · **Install** · [Usage](USAGE.md) · [Agents](../AGENTS.md) · [Development](DEVELOPMENT.md)

## Requirements

- Unraid **7.x** (tested: 7.1.2 – see [Limitations](../README.md#limitations))
- **Community Applications** plugin installed, and the *Apps* tab opened at least once (that
  downloads the app feed `ca-install` searches in)
- Docker service running
- Shell access (SSH or the WebUI terminal) as root

## Install the plugin

### From GitHub (once the repository is public)

WebUI: **Plugins → Install Plugin**, paste

```
https://github.com/tommibembel/unraid-ca-install/releases/latest/download/ca-install.plg
```

and click *Install*. Or on the command line:

```
plugin install https://github.com/tommibembel/unraid-ca-install/releases/latest/download/ca-install.plg
```

### From a local file

Download or build `ca-install.plg` (see below), copy it to the server and run:

```
plugin install /tmp/ca-install.plg
```

This is the same mechanism the WebUI uses; Unraid copies the file to `/boot/config/plugins/`.

### Check

```
ca-install version
```

## What gets installed

| Path | Content |
|---|---|
| `/boot/config/plugins/ca-install.plg` | the plugin file (on the flash drive; Unraid re-installs from it at every boot) |
| `/usr/local/emhttp/plugins/ca-install/` | `ca-install.php`, `webui-action.php`, `ca-install` (wrapper) – in RAM |
| `/usr/local/bin/ca-install` | symlink to the wrapper |

Nothing else is changed. The plugin has no settings and no WebUI page; it survives reboots
because Unraid re-installs all plugins in `/boot/config/plugins/` at boot.

## Update

- WebUI: **Plugins** → *Check for Updates* → *Update* (needs the public GitHub release).
- Command line: `plugin update ca-install.plg`
- From a newer local file: `plugin install /tmp/ca-install.plg` again.

## Remove

WebUI: **Plugins** → *ca-install* → *Remove*, or:

```
plugin remove ca-install.plg
```

This removes the program files and the command; Unraid moves the plugin file to
`/boot/config/plugins-removed/`.
Containers and templates installed with `ca-install` are **not** touched – they are normal
Unraid containers and stay manageable in the WebUI.

## Build from source

```
git clone https://github.com/tommibembel/unraid-ca-install.git
cd unraid-ca-install
./build.sh                 # version = today, YYYY.MM.DD
./build.sh 2026.11.16b     # explicit version
```

Result: `dist/ca-install.plg`. The program files from `src/ca-install/` are embedded in the
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

The Action then creates a GitHub release with `ca-install.plg`. The plugin's `pluginURL` points
to the latest release, so the Unraid *Plugins* tab offers the update automatically.
