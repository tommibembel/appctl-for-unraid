# unraid-ca-install

Install apps from the Unraid **Community Applications** store from the command line – with the
same result as clicking **Install → Apply** in the WebUI. Built for AI agents and scripts, usable
by humans.

**[Install](docs/INSTALL.md) · [Usage](docs/USAGE.md) · [For AI agents](AGENTS.md) · [Development](docs/DEVELOPMENT.md)**

```
$ ca-install search example-app
$ ca-install options 1234 --name example-app --set 80=8080
$ ca-install install 1234 --name example-app --set 80=8080 --dry-run
$ ca-install install 1234 --name example-app --set 80=8080 --yes
```

## Why

Unraid has no API for "install this app from Community Applications". The GraphQL API can
start/stop/update containers but not create them from a CA template; remote-controlling the WebUI
is fragile; hand-written templates and `docker run` commands miss the CA metadata, so icon,
update check and *Installed Apps* don't work properly.

`ca-install` instead runs the **original** CA and dockerMan code path:

1. CA's `createXML()` adapts the template to the server (network, disks/pools, appdata path),
2. the dockerMan *Add Container* form logic turns it into the form data the browser would send,
3. dockerMan's `postToXML()` / `xmlToCommand()` write `my-<name>.xml` and build the `docker run`
   command, then image pull, container start, optional autostart.

The result is a normal Unraid container: icon, update status, *Edit*, *Installed Apps* – exactly
like a click in the WebUI. Details: [DEVELOPMENT.md](docs/DEVELOPMENT.md).

## Features

- `search`, `info`, `options`, `install --dry-run`, `install --yes`
- **`options`** returns the whole form as data – every setting already adapted to the server, with
  required/secret/advanced flags, choice lists and problems (missing values, port conflicts with a
  free-port suggestion, name conflicts). An agent can ask the user exactly what needs deciding.
- Everything the *Add Container* form can do (except Tailscale): container fields, field values,
  field properties (*Edit*), add/remove fields, autostart
- `--json` output with stable error codes and exit codes
- Safe by default: nothing is installed without `--yes`; ambiguous apps, name conflicts, missing
  required values and host port conflicts stop before anything is changed

## Documentation

Where to go next:

| You want to … | Read |
|---|---|
| install, update or remove the plugin, or build it yourself | [docs/INSTALL.md](docs/INSTALL.md) |
| use the command: all options, JSON output, error codes, examples | [docs/USAGE.md](docs/USAGE.md) |
| let an AI agent install apps, or let an agent work on this code | [AGENTS.md](AGENTS.md) |
| understand how it works or check it against a new Unraid version | [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) |

Suggested order for a new user: this README → [Install](docs/INSTALL.md) →
[Usage](docs/USAGE.md#typical-workflow) (typical workflow first).

## Quick install

On the Unraid server (WebUI → *Plugins → Install Plugin*, or the terminal):

```
plugin install https://github.com/tommibembel/unraid-ca-install/releases/latest/download/ca-install.plg
```

Requires Unraid 7.x with the Community Applications plugin. See [INSTALL.md](docs/INSTALL.md).

## Limitations

- **Tested with Unraid 7.1.2 and Community Applications 2026.07.21.** It uses internal,
  non-public Unraid and CA functions, which can change with any update. Other versions are not
  checked or blocked – using them is up to you. Preview with `--dry-run` first.
- The dockerMan form runs in the browser; that part is replicated in PHP and is the piece most
  likely to need an update after an Unraid release.
- No Tailscale integration yet ([issue #1](https://github.com/tommibembel/unraid-ca-install/issues/1)).
- Docker containers only (no plugins from CA).
- Run as root on the server (like the WebUI).

## Credits

Community Applications by Andrew Zawadzki (Squid) and Lime Technology; dockerMan by Lime
Technology, Bergware and contributors. `ca-install` only calls and mirrors their code.

## License

GPL-2.0-only – see [LICENSE](LICENSE). The install logic mirrors parts of dockerMan (Unraid
webGUI, "GNU General Public License version 2") and Community Applications ("GPLv2"), so this
project uses the same license.
