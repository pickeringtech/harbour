#!/usr/bin/env bash

set -euo pipefail

harbour_source="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
worktrunk_binary="${HARBOUR_WORKTRUNK_BINARY:-wt}"
acceptance_root="$(mktemp -d "${TMPDIR:-/tmp}/harbour-worktrunk-acceptance-XXXXXXXX")"
project_root="${acceptance_root}/project ü ' ; dollar\$(nope)"
workspace_a="${project_root}.acceptance-a"
workspace_b="${project_root}.acceptance-b"
workspace_merge="${project_root}.acceptance-merge"

cleanup() {
    for workspace in "${workspace_a}" "${workspace_b}" "${workspace_merge}"; do
        if [[ -d "${workspace}" ]]; then
            git -C "${project_root}" worktree remove --force "${workspace}" >/dev/null 2>&1 || true
        fi
    done
    case "${acceptance_root}" in
        "${TMPDIR:-/tmp}"/harbour-worktrunk-acceptance-*) rm -rf -- "${acceptance_root}" ;;
        *) echo "Refusing to remove unexpected Worktrunk acceptance path [${acceptance_root}]." >&2 ;;
    esac
}
trap cleanup EXIT

export HARBOUR_STATE_HOME="${acceptance_root}/registry"
export LARAVEL_BYPASS_ENV_CHECK=1
export HARBOUR_ENABLED=true

composer create-project laravel/laravel:^13.0 "${project_root}" --no-interaction --prefer-dist
composer --working-dir="${project_root}" config repositories.harbour path "${harbour_source}"
composer --working-dir="${project_root}" require --dev pickeringtech/harbour:@dev --no-interaction
set -a
# The disposable fixture passes the source checkout's secrets to clean worktrees.
source "${project_root}/.env"
set +a
git -C "${project_root}" init --initial-branch=main
git -C "${project_root}" config user.name "Harbour CI"
git -C "${project_root}" config user.email "harbour@example.invalid"

(cd "${project_root}" && php artisan workspace:install --detect --database=none --worktree-hooks=worktrunk --json) \
    | php -r '$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(($result["installation"]["worktree_hooks"] ?? null) === ["worktrunk"] ? 0 : 1);'

php -r '
$path = $argv[1]."/composer.json";
$manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$setup = (array) $manifest["scripts"]["workspace:setup"];
$setup[] = "@php lifecycle.php setup";
$manifest["scripts"]["workspace:setup"] = $setup;
$teardown = (array) $manifest["scripts"]["workspace:teardown"];
array_unshift($teardown, "@php lifecycle.php teardown");
$manifest["scripts"]["workspace:teardown"] = $teardown;
file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
' "${project_root}"
cat >"${project_root}/lifecycle.php" <<'PHP'
<?php
$action = $argv[1] ?? '';
if ($action === 'setup') {
    file_put_contents('.lifecycle.log', "setup\n", FILE_APPEND);
    exit(0);
}
if ($action === 'teardown' && is_file('.fail-teardown')) {
    fwrite(STDERR, "injected teardown failure\n");
    exit(23);
}
PHP
printf '/.lifecycle.log\n/.fail-teardown\n' >>"${project_root}/.gitignore"

git -C "${project_root}" add --all
git -C "${project_root}" commit --quiet -m "Worktrunk acceptance fixture"

"${worktrunk_binary}" -C "${project_root}" --yes switch --create acceptance-a
"${worktrunk_binary}" -C "${project_root}" --yes switch --create acceptance-b

test -f "${workspace_a}/.harbour.json"
test -f "${workspace_b}/.harbour.json"
test "$(cat "${workspace_a}/.lifecycle.log")" = "setup"
test "$(cat "${workspace_b}/.lifecycle.log")" = "setup"

port_a="$(cd "${workspace_a}" && php artisan workspace:status --json | php -r '$s=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo $s["workspace"]["ports"]["APP_PORT"];')"
port_b="$(cd "${workspace_b}" && php artisan workspace:status --json | php -r '$s=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo $s["workspace"]["ports"]["APP_PORT"];')"
test "${port_a}" != "${port_b}"

(cd "${workspace_a}" && composer workspace:setup)
test "$(cat "${workspace_a}/.lifecycle.log")" = $'setup\nsetup'

touch "${workspace_a}/.fail-teardown"
if "${worktrunk_binary}" -C "${project_root}" --yes remove --foreground --force acceptance-a; then
    echo "Worktrunk removed a checkout after injected Harbour teardown failure." >&2
    exit 1
fi
test -d "${workspace_a}"
test -f "${workspace_a}/.harbour.json"

rm "${workspace_a}/.fail-teardown"
"${worktrunk_binary}" -C "${project_root}" --yes remove --foreground --force acceptance-a
test ! -d "${workspace_a}"
test -f "${workspace_b}/.harbour.json"
(cd "${workspace_b}" && php artisan workspace:status --json) \
    | php -r '$s=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(($s["workspace"]["status"] ?? null) === "ready" ? 0 : 1);'
"${worktrunk_binary}" -C "${project_root}" --yes remove --foreground --force acceptance-b

"${worktrunk_binary}" -C "${project_root}" --yes switch --create acceptance-merge
printf 'merged\n' >"${workspace_merge}/merged.txt"
git -C "${workspace_merge}" add merged.txt
git -C "${workspace_merge}" commit --quiet -m "Merge lifecycle fixture"
"${worktrunk_binary}" -C "${workspace_merge}" --yes merge --no-squash
test -f "${project_root}/merged.txt"
if [[ -d "${workspace_merge}" ]]; then
    test ! -f "${workspace_merge}/.harbour.json"
fi

echo "Harbour Worktrunk acceptance passed with real setup, retry, concurrency, blocked removal, cleanup, and merge."
