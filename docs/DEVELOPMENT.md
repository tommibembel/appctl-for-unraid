# Development

[README](../README.md) · [Install](INSTALL.md) · [Usage](USAGE.md) · [Agents](../AGENTS.md) · **Development**

How `appctl` works internally, what is original Unraid code and what is replicated, and how
to verify it on a new Unraid version. Read this before changing the install pipeline.

## Principle

The result must be indistinguishable from a WebUI install. So `appctl` does **not** write
its own templates or `docker run` commands. It walks through the same steps as the WebUI and calls
the original Community Applications and dockerMan functions wherever they can be called. Only
the parts that run in the browser (JavaScript) are replicated in PHP, with comments pointing to
the source lines.

Line references below are for **Unraid 7.1.2** unless marked *7.4* (Unraid 7.4.0), with
**Community Applications 2026.07.21**.
Paths are relative to `/usr/local/emhttp/plugins/`.

## Files

| File | Purpose |
|---|---|
| `src/appctl/appctl.php` | the CLI (single file) |
| `src/appctl/webui-action.php` | runs an original WebUI action script in a sub-process as if the WebUI had POSTed to it (whitelist: CA `exec.php`, dockerMan `UpdateConfig.php`) |
| `build.sh` | builds `dist/appctl.plg` with both files embedded |
| `plugin/CHANGES.md` | changelog shown in the Unraid Plugins tab |
| `README.md`, `AGENTS.md`, `docs/*.md` | documentation (`AGENTS.md` stays in the root, where coding agents look for it) |
| `tools/check-links.py` | checks relative links and anchors in the documentation (CI) |
| (in `appctl.php`) `mcp*()` | MCP server, see [MCP mode](#mcp-mode) |
| `.github/workflows/build.yml` | link check, lint, build, round-trip check, release on tag |

## The WebUI pipeline and how `appctl` maps it

### 1. CA "Install" button → `createXML()` — ORIGINAL

`community.applications/include/exec.php` ~line 2053. The Apps page POSTs
`{action: 'createXML', xml: <template path>, type: 'default'}`. `createXML()` takes the template
from the feed cache, adapts it to the system (missing network → `br0`/`eth0`, paths on missing
disks/pools → first present one, `/mnt/user/appdata/` → `DOCKER_APP_CONFIG_PATH`, case of existing
folders, icon per theme, Tailscale fallback dir) and writes it to the template's `Path` in CA's
cache. The browser then opens dockerMan with `?xmlTemplate=default:<path>`.

`exec.php` is an action-switch script (it executes `$_POST['action']` when included and defines
many globals), so `appctl` runs it in a separate process via `webui-action.php` with exactly
that POST → `runCreateXML()`.

### 2. dockerMan "Add Container" page load — PHP ORIGINAL, PHP block + JavaScript REPLICATED

`dynamix.docker.manager/include/CreateDocker.php`:

- `xmlToVar()` (`Helpers.php` line 142) — **original**, called directly.
- "LOAD TEMPLATE" block (lines 259-310): for `xmlType=default` the `/config` path is set to
  `DOCKER_APP_CONFIG_PATH/<Name>`, `/unraid` handling, overview cleanup. This is top-level code,
  not a function → **replicated** in `loadTemplateLikeDockerMan()`.
  *Deliberate deviation:* the final container name is used for the appdata path (see [USAGE.md](USAGE.md#behaviour-you-should-know)).
- Browser JavaScript that fills the form from `Settings` and builds the POST on submit
  → **replicated** in `formPost()`:
  - `Settings[key]` → `input[name=cont<key>]` (lines 1830-1855); `<select>`s only accept existing
    options (`contShell` sh/bash, `contNetwork` bridge/host/container/none/custom networks)
  - `showSubnet()` (lines 1535-1560) clears the fixed IP on bridge/host/none, handles `netCONT`
  - `makeConfig()` (lines 512-552) and `#templateDisplayConfig` (lines 1510-1521) for every field:
    values are inserted unescaped into HTML attributes via `String.format()` and parsed with
    `$.parseHTML` → entities are decoded once more, `"` ends a value, undefined attributes stay as
    the literal placeholder (`Mode="{3}"`); variables whose default contains `|` become a
    `<select>` (first option wins if the value is not an option, no `required`)
  - non-advanced fields go to `#configLocation`, advanced ones to `#configLocationAdvanced`, which
    comes later in the form → advanced fields are submitted after the others (lines 1876-1881);
    fields added via "Add another …" always go to `#configLocation` (line 637)
  - Device fields: `Target = Value` (line 1871)
  - `prepareConfig()` (lines 735-746): on `host` network port targets = values; CPU pinning
  - browser form encoding: text inputs drop line breaks, all line breaks become CRLF
- User input (what a human types into the form) → `applyUserInput()`:
  `--network/--ip/--container`, `--remove` (*Remove* button), `--add` (`addConfigPopup()`,
  lines 590-640, names from `makeName()`), `--set` value, `--set KEY.prop` (*Edit* dialog: attributes
  that did not exist become `''`).

### 3. "Apply" → `CreateDocker.php` lines 71-160 — ORIGINAL functions, flow REPLICATED

- `postToXML($post, true)` (`Helpers.php` line 64) — **original**
- `xmlToCommand($xml, $create_paths)` (`Helpers.php` line 321) — **original**
- `pullImage()`, `stopContainer()`, `removeContainer()`, `addRoute()` — **original**
- the flow itself (save `my-<Name>.xml`, icon cache cleanup, pull, stop/remove old, `docker run -d`)
  → **replicated** in `doInstall()`, with two changes: the image is pulled before the template is
  written; the command output is captured (`execCommand()` with `echo=false` would discard it).
- dockerMan's own dry run (`dryRun=true`) **saves the template too**, so it is not used;
  `--dry-run` only calls `postToXML()` and `xmlToCommand($xml, false)`.

### 4. `edit`: Edit → Apply for an existing container

- Load: `DockerTemplates::getUserTemplate($name)` — **original** (the Docker page's Edit link), then
  `CreateDocker.php` "LOAD TEMPLATE" with `xmlType=edit` (7.4: lines 332-390): `xmlToVar()`, the
  `container:` network split and the overview handling — **replicated** in `prepareEdit()` /
  `finishTemplateLoad()`. Unlike `xmlType=default` there are no `/config` and `/unraid` overrides.
- Form: same `formPost()` as for a new container, plus the hidden input `existingContainer` (only if
  the container exists, 7.4 line 983). 7.4 also posts `sourceTemplate` (line 980): Apply then writes
  to the edited file when the name is unchanged, otherwise to `getUserTemplatePath(<new name>)`;
  7.1 always writes `my-<name>.xml`.
- Apply (7.4 lines 129-235, `doInstall()` with `$existing`): remove the container with the new name;
  if renamed, stop/remove the old container (`removeContainer($existing, 1)`) and delete its template;
  if the old container was stopped, the new one is only created (`docker create`), not started.
  Deviations as for install (pull first, output captured), plus: a different container that already
  has the new name is only replaced with `--replace` (the WebUI replaces it silently).
- `--template-only`: only the template write (incl. icon cache cleanup).
- Changes are computed on `xmlToVar()` of the old and the new template (`settingsDiff()`), not on the
  bytes: libxml/`postToXML()` serialise differently between releases (`&#xD;` vs `&#13;`, empty
  elements for newer fields) without changing any setting.
- `/bin/sh -n` check of the generated command (`shellSyntaxError()`), also for `install` and in
  `options`: `xmlToCommand()` inserts `ExtraParams`/`PostArgs` unquoted, and dockerMan runs the
  command with `popen()`.

### 5. Differences between Unraid versions

The same code supports 7.1.x and 7.4. Newer form fields are detected by the dockerMan functions
that came with them (`newerFormFields()`), not by version numbers:

| Field | Since | Detected by | Notes |
|---|---|---|---|
| `contMyMAC` "Fixed MAC address" | 7.2.5 | `normalizeMacAddress()` | `showSubnet()` clears it on host/none/container; `prepareConfig()` lowercases and turns `-` into `:` |
| `contMemory` "Memory limit" | 7.4.0 | `connectExtraNetworks()` | input pattern `[0-9]+[bkmgBKMG]?` |
| `contExtraNetworks[]` "Additional Networks" | 7.4.0 | `connectExtraNetworks()` | multi-select of custom networks except host/none/bridge/container; after `docker run` the Apply flow calls `connectExtraNetworks()` |
| `contReadMe` | 7.4.0 | – | was already in the form, now saved by `postToXML()` |

Also new in 7.4: `DockerTemplates::getUserTemplatePath()` (case-insensitive template file lookup),
used when present (`userTemplatePath()`). `postToXML()` writes the new elements itself, so a default
install produces the same template on both versions.

### 6. Extras that are not part of the Apply flow

- CA app feed: lives in RAM (`/tmp/community.applications/tempFiles/templates_new.json`) and is gone
  after every reboot. When it is missing, `loadFeed()` calls CA's **original** `force_update()`
  (`exec.php` line 1141, what the Apps tab runs when opened) via `webui-action.php`;
  `appctl update-feed` does the same on demand. App IDs are assigned per download and change.
- `prepareCA()`: the Apps page creates `/tmp/CA_logs` (`Apps.page` line 37). Without it CA's
  `debug()` (`helpers.php` line 888) recurses until PHP runs out of memory, so every CA call failed
  after a reboot until the Apps tab had been opened. Created before any CA call.

- Autostart: the Docker page toggle POSTs `{action: 'autostart', container, wait, auto}` to
  `dynamix.docker.manager/include/UpdateConfig.php` → **original**, via `webui-action.php`
  (`setAutostart()`).
- Port conflict check (`portConflicts()`, `hostPortsInUse()`): not in the WebUI.
- Stale Docker page cache (`dropStaleWebuiInfo()`): `DockerClient::removeContainer()` drops the
  `webui-info` entry of a container; a container renamed outside dockerMan leaves one behind with
  an outdated update status. Dropped before starting the new container.

## MCP mode

`appctl mcp` (`mcpServe()`): JSON-RPC 2.0, one message per line on stdin/stdout, no dependencies.
Methods: `initialize` (answers with the client's protocol version if supported, else the newest
one in `MCP_PROTOCOL_VERSIONS`), `ping`, `tools/list`, `tools/call`; notifications are ignored.

- `mcpToOptions()` turns tool arguments into the same option array `parseArgs()` builds, then
  `runCommand()` runs the same code as the CLI. Unknown arguments are rejected.
- Results: one text block with the `--json` object, `isError` on errors. Output that Unraid code
  might print is buffered and sent to stderr; `display_errors` goes to stderr – stdout carries only
  protocol messages.
- One process serves many calls: `loadDockerMan()` flushes dockerMan's static caches, warnings are
  reset per call.
- Tool descriptions and `MCP_INSTRUCTIONS` carry the agent rules (confirmation, secrets, no guessing).

## Unraid / CA versions

There are deliberately **no version checks**: they would need maintenance with every Unraid
release, while the worst case without them is small (the tool fails with an error, or a template
differs in a detail from a WebUI install and can be fixed with *Edit*; `--dry-run` shows
everything beforehand). The README states the versions it was tested with.

The only check is `loadDockerMan()`: if a dockerMan function the tool calls does not exist, it
stops with `DOCKERMAN_CHANGED` instead of a PHP fatal error.

## Verifying on a new Unraid version

1. Read the diff of `CreateDocker.php` and `Helpers.php` (and CA's `createXML()`) against the line
   references above. Port any change in the JavaScript form logic into `formPost()` /
   `applyUserInput()`.
2. Pick an app, install it once **via the WebUI** (no changes in the form) and once with
   `appctl install <app> --name <other name> --dry-run`. Compare both `my-*.xml`: they must be
   identical except `Name`, `DateInstalled` and the appdata path that follows the name. Repeat
   with an app that has select variables, a password field and advanced fields, and with `--add`/`--set KEY.prop`/`--remove` against the same steps
   in the form.
3. Real install of a harmless app; check in the WebUI: icon, update status (*up-to-date*),
   *Edit* shows the same values, *Apps → Installed Apps* lists it.
4. `edit`: on that test container, save `appctl edit <name> <changes> --dry-run --json` (`.data.xml`),
   make the same changes in the WebUI (*Edit → Apply*) and compare the written `my-*.xml`: identical
   except `DateInstalled`. Also try a rename of a running and of a stopped container.
5. Update the tested versions in the README (Limitations), add a changelog entry, tag a release.

## Local testing

The build machine needs no PHP (`build.sh` only uses bash/sed); the code runs against the real
Unraid files. For testing, copy the files to the server (RAM, not the flash drive) and run them there:

```
scp src/appctl/*.php root@unraid:/tmp/appctl/
ssh root@unraid 'php /tmp/appctl/appctl.php options <app> --name test --json'
```

Read-only commands (`search`, `info`, `options`, `install --dry-run`, `edit --dry-run`) are safe to run on a
production server. `appctl.php` can be included without running `main()` by defining
`APPCTL_NO_MAIN` first – useful for testing single functions.

## Code conventions

- Comments in the code reference the replicated source (`CreateDocker.php line …`).
- Every error is a `AppctlError(code, message, exitCode, details)`; codes are part of the public
  interface ([USAGE.md](USAGE.md#exit-codes-and-error-codes), [AGENTS.md](../AGENTS.md)) – do not rename them.
- Output for humans in `printHuman()`, everything else is data.
