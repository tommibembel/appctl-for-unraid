# Usage

[README](../README.md) · [Install](INSTALL.md) · **Usage** · [Agents](../AGENTS.md) · [Development](DEVELOPMENT.md)

`appctl` installs Docker apps from the Unraid **Community Applications** (CA) store from the
command line. The result is the same as clicking **Install → Apply** in the WebUI: a
`my-<name>.xml` user template with the original CA metadata, icon, update check, *Edit* support
and an entry under *Apps → Installed Apps*.

- [Commands](#commands)
- [Typical workflow](#typical-workflow)
- [Options for `options` and `install`](#options-for-options-and-install)
- [Output and `--json`](#output-and---json)
- [Exit codes and error codes](#exit-codes-and-error-codes)
- [Behaviour you should know](#behaviour-you-should-know)
- [Examples](#examples)

For AI agents there is a short rule set in [AGENTS.md](../AGENTS.md). Installation: [INSTALL.md](INSTALL.md).

## Commands

| Command | What it does | Changes the server? |
|---|---|---|
| `appctl search <term> [--limit N]` | Search the CA feed (name, author, repository, description). Plugins, language packs and blacklisted apps are excluded. | no |
| `appctl info <app>` | Raw template from the CA feed (fields with defaults, as published). | no |
| `appctl options <app> [options]` | The *Add Container* form as data: every setting **already adapted to this server** (appdata path, network, …) plus problems (missing values, port conflicts, name conflicts) and `ready: true/false`. | no ¹ |
| `appctl install <app> [options] --dry-run` | Shows the resulting `my-*.xml` and the `docker run` command. | no ¹ |
| `appctl install <app> [options] --yes` | Really installs: pull image, write template, start container, optional autostart. | **yes** |
| `appctl update-feed` | Download / refresh the CA app feed with CA's own function (what opening the *Apps* tab does). Done automatically when the feed is missing, e.g. after a reboot. | CA's feed cache in RAM |
| `appctl version` | Tool, Unraid and CA versions. | no |
| `appctl --help` | Short help. | no |

¹ Like the WebUI's *Install* button, CA's `createXML()` writes its adapted template into CA's
cache in RAM (`/tmp/community.applications/tempFiles/…`). Nothing else is written.

**`<app>`** is one of:
- an **app ID** from `search` (e.g. `1234`) – unique, but only within the current CA feed: IDs
  **change whenever the feed is downloaded again** (e.g. after a reboot or `update-feed`). Use an ID
  right after `search`, never store it,
- an exact **repository**, written exactly as in the template including the tag
  (e.g. `ghcr.io/example/example-app` or `example/app:latest`),
- an exact **app name** (e.g. `example-app`).

If more than one template matches, nothing is guessed: the command fails with `AMBIGUOUS` and
lists the candidates with their IDs.

`install` without `--dry-run` or `--yes` does nothing and exits with `CONFIRMATION_REQUIRED`.

## Typical workflow

```
appctl search example-app                      # find the app, note the ID
appctl options 1234 --name example-app         # look at all settings and problems
appctl options 1234 --name example-app \
    --set 80=8080                                  # fix problems, repeat until "READY"
appctl install 1234 --name example-app \
    --set 80=8080 --dry-run                        # final preview
appctl install 1234 --name example-app \
    --set 80=8080 --yes                            # install
```

`options`, `install --dry-run` and `install --yes` take **the same arguments** and run through the
same code, so whatever `options` reports as ready also installs.

## Options for `options` and `install`

These cover everything the WebUI *Add Container* form can do, except the Tailscale section
(see [issue #1](https://github.com/tommibembel/appctl-for-unraid/issues/1)).

### Container

| Option | Meaning |
|---|---|
| `--name <name>` | Container name. Default: template name (spaces → `-`). Allowed: letters, digits, `_ . -`. |
| `--network <net>` | `bridge`, `host`, `none`, `container:<other container>` or a custom network (e.g. `br0`). `options` lists the available ones. |
| `--ip <address>` | Fixed IP; only for custom networks (e.g. `br0`). |
| `--container KEY=VALUE` | Any other container field (repeatable): `Repository`, `Registry`, `Shell` (`sh`/`bash`), `Privileged` (`true`/`false`), `WebUI`, `Icon`, `ExtraParams`, `PostArgs`, `CPUset` (e.g. `2,3`), `Category`, `Overview`, `Requires`, `Support`, `Project`, `TemplateURL`, `DonateText`, `DonateLink`. `Name`, `Network`, `MyIP` are aliases of the options above. |
| `--autostart` | Enable autostart (the toggle on the Docker page). |
| `--autostart-wait <sec>` | Autostart wait period (implies `--autostart`). |
| `--replace` | Allow replacing an existing container and/or `my-<name>.xml` with the same name. Without it, a name in use is an error. |

### Template fields (ports, paths, variables, labels, devices)

Fields are addressed by a **KEY**: the field's *Target* (container port, container path,
variable name, label name) or, if that is not unique, its *Name*. `options` shows the right key
for each field as `set_key`.

| Option | WebUI equivalent | Example |
|---|---|---|
| `--set KEY=VALUE` | typing a value | `--set PUID=99`, `--set 80=8080`, `--set /config=/mnt/user/appdata/x` |
| `--set KEY.PROP=VALUE` | *Edit* button of the field | `--set /data.mode=ro`, `--set 8080.display=advanced` |
| `--add TYPE:TARGET=VALUE` | *Add another Path, Port, Variable, Label or Device* | `--add Path:/backup=/mnt/user/backup`, `--add Variable:LOG_LEVEL=info`, `--add Label:com.example.backup=true`, `--add Device:/dev/dri` |
| `--remove KEY` | *Remove* button of the field | `--remove EXAMPLE_API_TOKEN` |

`PROP` is one of `name`, `target`, `default`, `description`, `mode`, `display`, `required`, `mask`:
- `mode`: Path `rw`, `rw,slave`, `rw,shared`, `ro`, `ro,slave`, `ro,shared`; Port `tcp`, `udp`
- `display`: `always`, `always-hide`, `advanced`, `advanced-hide`
- `required`, `mask`: `true`, `false`

New fields get the same defaults as in the WebUI (name `Host Path 1`, `Host Port 2`,
`Host Key 3`, …; mode `rw`/`tcp`; display `always`). Rename them with `--set TARGET.name=...`.

The operations are applied in this order: `--remove`, then `--add`, then `--set`.
Variables with a choice list (default like `true|false`) only accept one of the listed values.

### Other

| Option | Meaning |
|---|---|
| `--json` | Machine-readable output (see below). |
| `--limit N` | Number of `search` results (default 20). |

## Output and `--json`

Without `--json` the output is meant for humans; warnings go to stderr.
With `--json` every command prints exactly one JSON object on stdout:

```json
{ "ok": true,  "command": "options", "data": { ... }, "warnings": ["..."] }
{ "ok": false, "error": { "code": "PORT_CONFLICT", "message": "...", ...details }, "warnings": [] }
```

### `options` data

Example output (illustrative, shortened) for `appctl options 1234 --name example-app --json`.
Host port 80 is taken by the Unraid WebUI, so the template's default port conflicts:

```json
{
  "app": { "id": 1234, "name": "example-app", "author": "example",
           "repository": "ghcr.io/example/example-app", "deprecated": false, "compatible": true, "...": "..." },
  "ready": false,
  "problems": [
    { "code": "PORT_CONFLICT", "message": "host port tcp/80 used by listening socket on host",
      "suggested_value": "8080", "field": "80" }
  ],
  "container": { "name": "example-app", "network": "bridge", "fixed_ip": "",
                 "autostart": false, "autostart_wait": null },
  "container_fields": { "Repository": "ghcr.io/example/example-app", "Shell": "sh", "Privileged": "false",
                        "WebUI": "http://[IP]:[PORT:80]", "ExtraParams": "", "PostArgs": "", "...": "..." },
  "networks": [
    { "name": "bridge", "driver": "bridge",  "subnet": "",               "fixed_ip_possible": false },
    { "name": "br0",    "driver": "macvlan", "subnet": "192.168.1.0/24", "fixed_ip_possible": true }
  ],
  "fields": [
    { "set_key": "80", "name": "WebUI", "type": "Port", "target": "80", "value": "80", "default": "80",
      "choices": null, "mode": "tcp", "required": true, "secret": false, "advanced": false, "hidden": false,
      "description": "Web interface",
      "problems": [ { "code": "PORT_CONFLICT", "message": "host port tcp/80 used by listening socket on host",
                      "suggested_value": "8080" } ] }
  ],
  "requires": "",
  "overview": "Example App does ...",
  "support": "https://github.com/example/example-app/issues",
  "how_to_change": "Re-run with --name, --network, ... until ready=true; then: install <same arguments> --dry-run, and finally --yes."
}
```

Field attributes:

| Key | Meaning |
|---|---|
| `set_key` | KEY to use with `--set`/`--remove` (`null` if the field cannot be addressed uniquely – rare). |
| `value` | Value that will be used, already adapted to this server. |
| `default` | Template default. An empty value falls back to it (see below). |
| `choices` | Allowed values for select fields, otherwise `null`. |
| `required` | Must not be empty. |
| `secret` | Password-type field (*Mask*). The value is included in JSON; never echo it to a user or log. |
| `advanced` | Shown under *Show more settings* in the WebUI – usually fine to keep. |
| `hidden` | Not shown in the WebUI at all. |
| `problems` | `MISSING_REQUIRED` or `PORT_CONFLICT` (with `suggested_value`). |

Top-level `problems` additionally contains `NAME_CONFLICT` (unless `--replace` is given).

### `install` data

`name`, `repository`, `template_file`, `xml` (the complete `my-*.xml`), `command` (the `docker run`
command), `replaces` (what `--replace` will replace), `autostart`, `dry_run`, `installed`; after a
real install also `container_id` and `steps` (e.g. `pulled …`, `wrote …`, `started container`,
`autostart enabled`).

### `search` / `info` data

`search`: `{ "total": 7, "results": [ { "id", "name", "author", "repository", "repo", "deprecated", "compatible", "overview" } ] }`.
`info`: the same summary plus `network`, `webui`, `template_url`, `support`, `project`, `requires`
and `config` (raw template fields).

## Exit codes and error codes

| Exit | Meaning | Error codes |
|---|---|---|
| 0 | ok (`options` also returns 0 when `ready` is false) | – |
| 1 | internal error | `INTERNAL`, `TEMPLATE_PATH` |
| 2 | usage / invalid input | `USAGE`, `INVALID_NAME`, `INVALID_NETWORK`, `INVALID_VALUE`, `UNKNOWN_FIELD` |
| 3 | app not found | `NOT_FOUND` |
| 4 | ambiguous | `AMBIGUOUS` (app, with `candidates`), `AMBIGUOUS_FIELD` (field, with `fields`) |
| 5 | required field without value | `MISSING_REQUIRED` (with `missing`) |
| 6 | name in use | `NAME_CONFLICT` (with `conflicts`) |
| 7 | environment / version | `CA_NOT_INSTALLED`, `FEED_DOWNLOAD_FAILED` (no internet / DNS), `FEED_INVALID`, `DOCKERMAN_CHANGED` (a dockerMan function the tool calls is missing), `DOCKER_NOT_RUNNING` |
| 8 | install failed | `CREATEXML_FAILED`, `UNSUPPORTED`, `PULL_FAILED`, `WRITE_FAILED`, `RUN_FAILED`, `AUTOSTART_FAILED` |
| 9 | confirmation required | `CONFIRMATION_REQUIRED` |
| 10 | host port in use | `PORT_CONFLICT` (with `conflicts`, each with `suggested_port`) |

Error details: `UNKNOWN_FIELD` lists all `fields` (or `available` container fields),
`INVALID_NETWORK` lists `available` networks, `RUN_FAILED` contains the docker `output` and a `hint`.

## Behaviour you should know

**Same as the WebUI (on purpose):**
- *Empty value → default.* dockerMan uses a field's default when its value is empty. You cannot
  "empty" a field that has a default; use `--remove KEY` instead. `appctl` warns about this.
- Template quirks are reproduced byte for byte, e.g. `Mode="{3}"` on variables without a mode
  and CRLF line breaks in the description – so the result is identical to a WebUI install.
- On `bridge`/`host` networks port fields are published host ports; on custom networks like
  `br0` (macvlan/ipvlan) the container has its own IP and the port fields are only passed as
  `TCP_PORT_<n>` variables.
- `--replace` stops and removes an existing container with that name, then creates the new one.

**Different from the WebUI (on purpose):**
- *Appdata path follows the container name.* In the WebUI the `/config` appdata path keeps the
  template name even if you rename the container – a second instance would share data with the
  first one. `appctl` uses the final name (`/mnt/user/appdata/<name>`).
- *Port conflict check.* A human sees conflicts in the form, an agent doesn't. Before installing,
  host ports on `bridge`/`host` networks are checked against listening sockets and the port
  bindings of all existing containers (also stopped ones, as warning). Conflicts stop the install
  and come with a free port suggestion (port + 8000, e.g. 80 → 8080).
- *Name conflicts stop the install.* CA would silently append `-1`; `appctl` refuses unless
  `--replace` or a different `--name` is given.
- *Image first.* The image is pulled before the template is written, so a failed pull leaves
  nothing behind.
- *Stale Docker page cache.* If the Docker page still has cached info for a name without a
  container (e.g. after a container was renamed outside dockerMan), it is cleared, so update
  status and links are correct.

**Not supported yet:** the Tailscale section of the form ([issue #1](https://github.com/tommibembel/appctl-for-unraid/issues/1)).
Templates that enable Tailscale are refused with `UNSUPPORTED`.

**What a real install writes:**
- `/boot/config/plugins/dockerMan/templates-user/my-<name>.xml` (the template)
- host directories of path fields that do not exist yet (owner `nobody:users`, like the WebUI)
- the image, the container, and with `--autostart` an entry in `/var/lib/docker/unraid-autostart`

## Examples

Search and inspect:
```
appctl search example --limit 5
appctl info 1234
```

Install on a custom network with a fixed IP, drop a placeholder field, load secrets from an env
file instead of the template, enable autostart:
```
appctl install 1234 --name example-app --network br0 --ip 192.168.1.20 \
    --remove EXAMPLE_API_TOKEN \
    --container ExtraParams="--env-file /mnt/user/appdata/example-app/secrets.env" \
    --autostart --dry-run
```

Replace a hand-made container/template with the proper CA template (same name):
```
appctl install 1234 --name example-app --replace ... --yes
```

Add a read-only path and a label, change a field's display:
```
appctl install <app> --add Path:/media=/mnt/user/media --set /media.mode=ro \
    --add Label:com.example.backup=true --set 8080.display=advanced --dry-run
```

Agent-style JSON:
```
appctl options 1234 --name example-app --json | jq '.data.problems'
appctl install 1234 --name example-app --set 80=8080 --yes --json | jq '.data.steps'
```
