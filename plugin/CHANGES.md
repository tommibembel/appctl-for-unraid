###2026.11.16c
- New command `edit`: change an existing container like Edit -> Apply on the Docker page (same options as install, rename, --template-only); `options --edit` shows the form and the pending changes
- Shell syntax check of the generated docker command before install/edit (raw labels in Extra Parameters would break the next update)

###2026.11.16
- First plugin release
- Downloads the CA app feed automatically when missing (e.g. after a reboot); `update-feed` command
- Commands: search, info, options, install (--dry-run / --yes), version
- Install via original CA createXML() and dockerMan postToXML()/xmlToCommand()
- Covers the whole "Add Container" form except Tailscale (--container, --set, --set KEY.prop, --add, --remove, --autostart)
- Host port conflict check with suggested free ports
- Unraid 7.4 support: MAC address (7.2.5+), memory limit and additional networks (7.4); additional networks are connected after the start like the WebUI
- Tested on Unraid 7.1.2, 7.1.4 and 7.4.0 with Community Applications 2026.07.21
