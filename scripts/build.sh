#!/usr/bin/env bash
# Build the unraid-netbird-utils-<version>-noarch-1.txz Slackware package
# from src/, and emit a final plugin/netbird.plg with the package SHA256
# filled in. Run from the repo root.
#
# Usage:
#   ./scripts/build.sh                  # uses version from plugin/plugin.json,
#                                       # or YYYY.MM.DD.HHMM otherwise.
#   VERSION=2026.05.16.0001 ./scripts/build.sh
#
# Outputs to dist/.

set -euo pipefail

repo_root=$(cd "$(dirname "$0")/.." && pwd)
cd "$repo_root"

VERSION="${VERSION:-$(date -u +%Y.%m.%d.%H%M)}"
PKG_NAME="unraid-netbird-utils"
PKG_FILE="${PKG_NAME}-${VERSION}-noarch-1.txz"
DIST_DIR="${repo_root}/dist"
STAGE_DIR="${DIST_DIR}/stage"

echo ">> Building ${PKG_FILE}"
rm -rf "${DIST_DIR}"
mkdir -p "${DIST_DIR}"
mkdir -p "${STAGE_DIR}"

# Mirror src/ into the staging directory. COPYFILE_DISABLE stops macOS cp/tar
# from emitting AppleDouble (._*) sidecar files for extended attributes.
export COPYFILE_DISABLE=1
cp -R src/. "${STAGE_DIR}/"

# Belt-and-suspenders: drop any sidecar files and macOS .DS_Store turds.
find "${STAGE_DIR}" \( -name '._*' -o -name '.DS_Store' \) -delete

# Ensure CRLF -> LF on any shell/page/cfg files.
find "${STAGE_DIR}" -type f \( -name "*.sh" -o -name "*.page" -o -name "*.php" -o -name "*.cfg" -o -name "rc.netbird" \) -exec sed -i.bak 's/\r$//' {} \;
find "${STAGE_DIR}" -name "*.bak" -delete

# Permissions.
find "${STAGE_DIR}" -type d -exec chmod 0755 {} \;
find "${STAGE_DIR}" -type f -exec chmod 0644 {} \;
find "${STAGE_DIR}" -name "*.sh" -exec chmod 0755 {} \;
chmod 0755 "${STAGE_DIR}/usr/local/etc/rc.d/rc.netbird"

# Build the package. Prefer makepkg (Slackware native); fall back to tar.
cd "${STAGE_DIR}"
if command -v makepkg >/dev/null 2>&1; then
    /sbin/makepkg -l y -c n "${DIST_DIR}/${PKG_FILE}"
else
    echo "(makepkg not found; producing tar.xz with the same layout)"
    # Passing "." to tar prefixes every payload member with "./". Slackware's
    # installpkg repairs those names in its file manifest, but upgradepkg's
    # temporary install-script relocation only matches "install/...". The
    # result is a silently skipped doinst.sh on upgrades. Archive the top-level
    # entries by name so install/doinst.sh is recognized in both install modes.
    (
        shopt -s dotglob nullglob
        package_entries=(*)
        if [ "${#package_entries[@]}" -eq 0 ]; then
            echo "ERROR: package staging directory is empty" >&2
            exit 1
        fi
        tar --owner=0 --group=0 -cJf "${DIST_DIR}/${PKG_FILE}" "${package_entries[@]}"
    )
fi

# Guard the upgrade-sensitive package layout regardless of which builder was
# used. A Slackware package may contain the root member "./", but payload paths
# (especially install/doinst.sh) must not carry that prefix.
package_has_doinst=0
while IFS= read -r member; do
    if [ "$member" = "install/doinst.sh" ]; then
        package_has_doinst=1
    elif [[ "$member" == ./* && "$member" != "./" ]]; then
        echo "ERROR: invalid Slackware package member '$member' (unexpected ./ prefix)" >&2
        exit 1
    fi
done < <(tar -tJf "${DIST_DIR}/${PKG_FILE}")

if [ "$package_has_doinst" -ne 1 ]; then
    echo "ERROR: package is missing install/doinst.sh at the archive root" >&2
    exit 1
fi

cd "${repo_root}"

# SHA256 of the package.
if command -v sha256sum >/dev/null 2>&1; then
    PKG_SHA256=$(sha256sum "${DIST_DIR}/${PKG_FILE}" | awk '{print $1}')
else
    PKG_SHA256=$(shasum -a 256 "${DIST_DIR}/${PKG_FILE}" | awk '{print $1}')
fi

echo ">> Package SHA256: ${PKG_SHA256}"

# Emit a finalized .plg with the version + SHA256 substituted in.
PLG_IN="plugin/netbird.plg"
PLG_OUT="${DIST_DIR}/netbird.plg"
sed \
  -e "s|<!ENTITY version     \"[^\"]*\">|<!ENTITY version     \"${VERSION}\">|" \
  -e "s|<!ENTITY pkgSHA256     \"[^\"]*\">|<!ENTITY pkgSHA256     \"${PKG_SHA256}\">|" \
  "${PLG_IN}" > "${PLG_OUT}"

echo ">> Wrote ${PLG_OUT}"
echo ">> Wrote ${DIST_DIR}/${PKG_FILE}"
echo
echo "Next steps:"
echo "  1. Upload dist/${PKG_FILE} to GitHub Releases under tag ${VERSION}."
echo "  2. Commit dist/netbird.plg back to plugin/netbird.plg on main."
echo "  3. Install on Unraid via Plugin Manager:"
echo "       https://raw.githubusercontent.com/netbirdio/netbird-unraid/main/plugin/netbird.plg"
