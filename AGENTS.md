# AGENTS.md

[README](README.md) · [Install](docs/INSTALL.md) · [Usage](docs/USAGE.md) · **Agents** · [Development](docs/DEVELOPMENT.md)

Instructions for AI agents (Claude Code, Codex, Hermes, …). Two parts:

- **[Part 1 – Using appctl](#part-1--using-appctl)**: installing Community Applications on
  an Unraid server with the tool.
- **[Part 2 – Working on this repository](#part-2--working-on-this-repository)**: goals,
  principles and safety rules when changing or testing the code.

# Part 1 – Using appctl

Full reference: [USAGE.md](docs/USAGE.md).

## Access

`appctl` runs on the Unraid server. Call it over SSH and always use `--json`:

```
ssh <unraid-host> appctl <command> ... --json
```

Parse stdout as one JSON object. Check `ok`; on `false` act on `error.code` (table below).
Always read `warnings` and pass relevant ones on to the user.

## Rules

1. **Never install without the user's explicit confirmation.** Show the user what will be
   installed (`install … --dry-run`), wait for a clear "yes", then run the identical command
   with `--yes` instead of `--dry-run`.
2. **Never guess.** If `<app>` is ambiguous, show the candidates (name, repository, author, ID)
   and let the user choose. Use the **ID** from then on.
3. **Ask, don't invent.** Values the user has to decide (passwords, domains, host paths for
   their data, ports when there is a conflict) come from the user. Defaults that `options`
   already filled in may be kept – say that you keep them.
4. **Secrets:** fields with `"secret": true` must not be echoed back in chat, logs or summaries.
   Ask the user for them; mention only that they are set.
5. **`--replace` only on explicit request.** A `NAME_CONFLICT` means something with that name
   exists. Propose another `--name` or ask whether to replace it – replacing stops and removes the
   existing container.
6. **Do not edit `my-*.xml` files or run `docker run` yourself.** Everything the WebUI form can do
   is available as an option (`--container`, `--set`, `--set KEY.prop`, `--add`, `--remove`,
   `--autostart`). If something is not possible, tell the user instead of working around the tool.
7. **Environment errors (`exit 7`) are not yours to fix.** Report them to the user.
   `DOCKERMAN_CHANGED` means this Unraid version lacks a function the tool needs.

## Workflow

```
1. search      appctl search <term> --json
               -> pick the app with the user; remember its "id"
2. options     appctl options <id> --name <name> [--network …] --json
               -> loop:
                  - data.problems empty and data.ready == true  -> step 3
                  - otherwise ask the user about each problem and about the
                    non-advanced fields they may want to change, then re-run
                    options with the answers (same arguments + new ones)
3. preview     appctl install <id> <same arguments> --dry-run --json
               -> show the user: name, image (data.repository), network/IP,
                  ports, paths, variables (no secrets), autostart
4. confirm     wait for the user's "yes"
5. install     appctl install <id> <same arguments> --yes --json
               -> report data.steps; on error report error.message and stop
```

What to ask in step 2 – keep it short:
- Container **name** and **network** (show `data.networks`; `br0`-like networks give the container
  its own LAN IP and need `--ip` or DHCP from Docker's range).
- Every field in `problems` (use `suggested_value` as proposal for port conflicts).
- Required fields without a sensible value, and every `secret` field.
- Host paths for user data (media, documents, …). Appdata paths under `/mnt/user/appdata/<name>`
  are normally fine as proposed.
- Fields with `advanced: true` only if the user wants to go through them.
- Show `data.requires` if not empty (e.g. "needs a database").
- Autostart: propose `--autostart` for services that should survive a reboot.

## Error codes → what to do

| `error.code` | Action |
|---|---|
| `AMBIGUOUS` | Show `error.candidates`, let the user choose, use the ID. |
| `NOT_FOUND` | Run `search` with a broader term. |
| `NAME_CONFLICT` | Propose another `--name`, or ask about `--replace` (rule 5). |
| `MISSING_REQUIRED` | Ask the user for each entry in `error.missing`, add `--set KEY=VALUE`. |
| `PORT_CONFLICT` | Propose `suggested_port` per entry in `error.conflicts`, or a custom network. |
| `UNKNOWN_FIELD`, `AMBIGUOUS_FIELD` | Use a `set_key` from `options` (`error.fields` lists them). |
| `INVALID_VALUE`, `INVALID_NETWORK`, `INVALID_NAME`, `USAGE` | Fix the argument (the message says what is allowed). |
| `CONFIRMATION_REQUIRED` | You forgot `--dry-run`/`--yes`. |
| `UNSUPPORTED` | Template needs a feature the tool lacks (e.g. Tailscale) – tell the user. |
| `PULL_FAILED`, `RUN_FAILED`, `WRITE_FAILED`, `AUTOSTART_FAILED` | Report `message` (and `output`/`hint`) to the user; do not retry blindly. After `RUN_FAILED` the template is saved; a retry needs `--replace`. |
| exit 7 codes | Report to the user (rule 7). |

## Gotchas

- An **empty value falls back to the field's default** (dockerMan behaviour). To get rid of a
  placeholder value, use `--remove KEY`, not `--set KEY=`.
- `options` returns exit code 0 even when not ready – check `data.ready`.
- Pass the **same arguments** to `options`, `--dry-run` and `--yes`. Keep them in one list and
  only append to it.
- Quote values with spaces or shell characters. Over SSH the remote shell parses the command a
  second time, so quote twice or pass the command via a heredoc:
  ```
  ssh <unraid-host> bash -s <<'EOF'
  appctl install 1234 --name example-app \
    --container ExtraParams="--env-file /mnt/user/appdata/example-app/secrets.env" \
    --set 'APP_TITLE=My "special" server' --dry-run --json
  EOF
  ```

# Part 2 – Working on this repository

Read [DEVELOPMENT.md](docs/DEVELOPMENT.md) for the architecture (original vs. replicated code, source
line references, how to verify a new Unraid version).

## Goal

A small Unraid plugin with a CLI command `appctl` that installs Community Applications
**with exactly the same result as Install → Apply in the WebUI**: `my-<name>.xml` with the
original CA metadata (TemplateURL, Icon, Support, …), icon, update check, *Edit*, entry under
*Installed Apps*.

**Not** a re-implementation "from the outside" (hand-written XML plus `docker run`), but a call
of the **original Unraid / CA functions**.

Why not something else:
- **Unraid GraphQL API**: can start/stop/update/remove containers and install plugins, but cannot
  create containers from CA templates (on 7.1 also only via the Unraid Connect plugin).
- **Remote-controlling the WebUI**: works, but fragile and needs a login session.
- **Hand-written templates**: CA compatibility (icon, update check, *Installed Apps*) not guaranteed.

## Principles

- Use the original CA / dockerMan functions (`require` or the `webui-action.php` runner) wherever
  possible. Where that is impossible (browser JavaScript, top-level code in action scripts),
  replicate the logic **minimally** and comment it with file and line of the source.
- The output must be **byte-identical** to a WebUI install (except `DateInstalled` and the
  documented deviations). Acceptance test: compare with a `my-*.xml` created by clicking in the
  WebUI with the same input.
- Never guess: ambiguous app → list and stop; required value missing → stop with a clear message;
  name already used by a container or template → stop unless `--replace`.
- Fail clearly instead of installing halfway.
- No version gates or checks that need maintenance with every Unraid release. The README states
  the tested versions; using other versions is up to the user. Only check what the tool cannot
  work without (e.g. that the called functions exist).
- Machine-readable first: every new behaviour needs `--json` output and, for errors, a stable
  error code and exit code (documented in [USAGE.md](docs/USAGE.md)).
- Keep it a single small plugin: files embedded in the `.plg`, no settings page, no daemon.

## Definition of done for a change

- `install --dry-run` output matches a WebUI install for the same input.
- A real install of a harmless test app shows icon, update status *up-to-date*, *Edit* with the
  same values and an entry under *Installed Apps*.
- `--dry-run` and `--json` work; error codes and exit codes are documented.
- The GitHub Action is green (PHP lint, build, round-trip check).
- [USAGE.md](docs/USAGE.md), this file and [plugin/CHANGES.md](plugin/CHANGES.md) are updated.

## Safety rules when testing on a real Unraid server

Test servers are usually somebody's production NAS. Therefore:

- **Never touch authentication / account files**: `/boot/config/passwd`, `/boot/config/shadow`,
  `/etc/passwd`, `/etc/shadow`, `authentication.conf`. Never type or store passwords, never bypass
  the WebUI login.
- **Reading is fine; writing needs the owner's explicit OK** for that specific step: installing
  plugins, files under `/boot`, pulling images, creating/starting/stopping/removing containers,
  changing app configs. An OK for one step does not cover the next kind of action.
- `search`, `info`, `options` and `install --dry-run` are read-only (except CA's own cache in RAM)
  and safe to run.
- Put test copies of the code in `/tmp` (RAM), never on the flash drive (`/boot`).
- Never stop, recreate or remove containers that are not the agreed test object. Before replacing
  something, back up what will be overwritten and keep a way back.
- `docker inspect` shows environment variables in clear text. Never repeat secrets found there.
- **Never commit private data**: no host names, IP addresses, domains, SSH aliases, key names,
  e-mail addresses, tokens or details about someone's server. Use placeholders like
  `<unraid-host>`, `192.168.1.x`, `example.com` in docs and examples. Machine-specific notes belong
  in local, untracked files.

## Working style

- Propose first, then act: describe the next concrete step (what, where, what it changes, how to
  undo it) and wait for the owner's go before anything with side effects.
- Read and understand the relevant Unraid source once, thoroughly, instead of trial and error on
  the server.
- Small, focused commits; commit messages explain *why*.
