#!/usr/bin/env bash
# ca-install – install Community Applications from the command line
# Copyright (C) 2026 tommibembel
# SPDX-License-Identifier: GPL-2.0-or-later
#
# Builds dist/ca-install.plg with all program files embedded (no separate package).
# Usage: ./build.sh [VERSION]   (default: today, YYYY.MM.DD)
set -euo pipefail
cd "$(dirname "$0")"

VERSION="${1:-$(date +%Y.%m.%d)}"
VERSION="${VERSION#v}"
[[ "$VERSION" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] || { echo "invalid version '$VERSION' (YYYY.MM.DD[a-z])" >&2; exit 1; }

NAME=ca-install
GITHUB=tommibembel/unraid-ca-install
SRC=src/ca-install
OUT=dist/$NAME.plg
mkdir -p dist

# Embedded files must not contain the CDATA terminator.
for f in "$SRC"/*.php; do
  if grep -q ']]>' "$f"; then echo "$f contains ']]>' – cannot embed in CDATA" >&2; exit 1; fi
done

embed() {  # embed <target path> <mode> <source file> [sed expression]
  printf '<FILE Name="%s" Mode="%s">\n<INLINE><![CDATA[' "$1" "$2"
  if [[ -n "${4:-}" ]]; then sed -e "$4" "$3"; else cat "$3"; fi
  printf ']]></INLINE>\n</FILE>\n\n'
}

{
cat <<EOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "$NAME">
<!ENTITY author    "tommibembel">
<!ENTITY version   "$VERSION">
<!ENTITY github    "$GITHUB">
<!ENTITY pluginURL "https://github.com/&github;/releases/latest/download/&name;.plg">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" min="7.0.0" support="https://github.com/&github;/issues" icon="terminal">

<CHANGES>
##&name;
$(cat plugin/CHANGES.md)
</CHANGES>

<!-- Install: remove files of a previous version -->
<FILE Run="/bin/bash">
<INLINE>
rm -rf &plugdir;
rm -f /usr/local/bin/&name;
mkdir -p &plugdir;
</INLINE>
</FILE>

EOF
embed "&plugdir;/ca-install.php"   0644 "$SRC/ca-install.php" "s/^const CAI_VERSION = '.*';/const CAI_VERSION = '$VERSION';/"
embed "&plugdir;/webui-action.php" 0644 "$SRC/webui-action.php"
cat <<'EOF'
<FILE Name="&plugdir;/ca-install" Mode="0755">
<INLINE><![CDATA[#!/bin/bash
exec /usr/bin/php /usr/local/emhttp/plugins/ca-install/ca-install.php "$@"
]]></INLINE>
</FILE>

<!-- Post-install: command on PATH -->
<FILE Run="/bin/bash">
<INLINE>
ln -sf &plugdir;/&name; /usr/local/bin/&name;
echo ""
echo "----------------------------------------------------"
echo " &name; &version; installed"
echo " Usage: ca-install --help"
echo "----------------------------------------------------"
echo ""
</INLINE>
</FILE>

<!-- Remove -->
<FILE Run="/bin/bash" Method="remove">
<INLINE>
rm -f /usr/local/bin/&name;
rm -rf &plugdir;
rm -rf /boot/config/plugins/&name;
echo "&name; removed"
</INLINE>
</FILE>

</PLUGIN>
EOF
} > "$OUT"

if command -v xmllint >/dev/null; then xmllint --noout "$OUT"; fi
echo "built $OUT (version $VERSION)"
