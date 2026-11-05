#!/usr/bin/env bash
# Links the local Oro documentation checkout as .orodocumentation in the project root. The checkout comes from
# vscodePresets.oroDocumentation in .vscode/settings.local.json; the task passes it in ORO_DOCUMENTATION.
set -euo pipefail

# Git Bash on Windows copies the folder when it cannot make a real symlink; this makes ln fail instead. A real symlink
# there needs Developer Mode or an administrator shell.
case $(uname -s) in MINGW* | MSYS* | CYGWIN*) export MSYS=winsymlinks:nativestrict ;; esac

src=${ORO_DOCUMENTATION:-}
if [ -z "$src" ]; then
    echo "Set vscodePresets.oroDocumentation in .vscode/settings.local.json to the Oro documentation checkout."
    exit 1
fi
case $src in \~|\~/*) src=$HOME${src#\~} ;; esac

link="${1:?project root}/.orodocumentation"
if [ ! -d "$src" ]; then
    echo "Source folder not found: $src"
    exit 1
fi
if [ -L "$link" ]; then
    echo "Symlink already exists: $link -> $(readlink "$link")"
    exit 0
fi
if [ -d "$link" ]; then
    if [ -L "$link/orodocumentation" ]; then rm "$link/orodocumentation"; fi
    if ! rmdir "$link" 2>/dev/null; then
        echo "$link is a folder that is not empty, move its contents first"
        exit 1
    fi
elif [ -e "$link" ]; then
    echo "A file already exists at $link"
    exit 1
fi
ln -s "$src" "$link"
echo "Created symlink: $link -> $src"
