#!/usr/bin/env bash

set -euo pipefail

if [[ "${HARBOUR_ORCA_INTEGRATION:-0}" != "1" ]]; then
    echo "Set HARBOUR_ORCA_INTEGRATION=1 to probe the real Orca lifecycle contract." >&2
    exit 1
fi

harbour_source="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ -n "${HARBOUR_ORCA_BINARY:-}" ]]; then
    orca_binary="${HARBOUR_ORCA_BINARY}"
elif [[ "$(uname -s)" == "Linux" ]]; then
    orca_binary="orca-ide"
else
    orca_binary="orca"
fi
acceptance_root="$(mktemp -d "${TMPDIR:-/tmp}/harbour-orca-acceptance-XXXXXXXX")"
project_root="${acceptance_root}/project ü ' ; dollar\$(nope)"
repo_id=""
workspace_container=""
declare -a created_worktrees=()

json_value() {
    php -r '
    $document = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $value = $document;
    foreach (explode(".", $argv[1]) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            exit(2);
        }
        $value = $value[$segment];
    }
    if (!is_scalar($value)) {
        exit(3);
    }
    echo $value;
    ' "$1"
}

cleanup() {
    for worktree in "${created_worktrees[@]:-}"; do
        if [[ -n "${worktree}" && -d "${worktree}" ]]; then
            "${orca_binary}" worktree rm --worktree "path:${worktree}" --force --json >/dev/null 2>&1 || true
        fi
    done
    if [[ -n "${workspace_container}" ]]; then
        rmdir "${workspace_container}/.orca-worktree-trash" >/dev/null 2>&1 || true
        rmdir "${workspace_container}" >/dev/null 2>&1 || true
    fi
    case "${acceptance_root}" in
        "${TMPDIR:-/tmp}"/harbour-orca-acceptance-*) rm -rf -- "${acceptance_root}" ;;
        *) echo "Refusing to remove unexpected Orca acceptance path [${acceptance_root}]." >&2 ;;
    esac
}
trap cleanup EXIT

wait_for_file() {
    local path="$1"
    local attempts=0
    until [[ -f "${path}" ]]; do
        attempts=$((attempts + 1))
        if (( attempts >= 120 )); then
            echo "Timed out waiting for Orca lifecycle evidence [${path}]." >&2
            "${orca_binary}" terminal list --worktree "path:$(dirname "${path}")" --json >&2 || true
            return 1
        fi
        sleep 1
    done
}

create_worktree() {
    local name="$1"
    local output_path="$2"
    shift 2
    "${orca_binary}" worktree create \
        --repo "id:${repo_id}" \
        --name "${name}" \
        --setup run \
        "$@" \
        --json >"${output_path}"
}

"${orca_binary}" status --json | php -r '
$status = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
exit(($status["result"]["runtime"]["state"] ?? null) === "ready" ? 0 : 1);
'
"${orca_binary}" agent-context --json | php -r '
$schema = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$required = ["worktree create" => "setup", "worktree rm" => "run-hooks"];
foreach ($required as $name => $flag) {
    $matches = array_values(array_filter($schema["commands"] ?? [], fn ($command) => ($command["command"] ?? null) === $name));
    if (count($matches) !== 1 || !in_array($flag, $matches[0]["flags"] ?? [], true)) {
        exit(1);
    }
}
'

export HARBOUR_STATE_HOME="${acceptance_root}/registry"
export LARAVEL_BYPASS_ENV_CHECK=1
export HARBOUR_ENABLED=true

composer create-project laravel/laravel:^13.0 "${project_root}" --no-interaction --prefer-dist
composer --working-dir="${project_root}" config repositories.harbour path "${harbour_source}"
composer --working-dir="${project_root}" require --dev pickeringtech/harbour:@dev --no-interaction
set -a
# The disposable fixture passes the source checkout's generated app key to its worktrees.
source "${project_root}/.env"
set +a
git -C "${project_root}" init --initial-branch=main
git -C "${project_root}" config user.name "Harbour CI"
git -C "${project_root}" config user.email "harbour@example.invalid"

(cd "${project_root}" && php artisan workspace:install --detect --database=none --cache=file --mail=log --with=none --worktree-hooks=none --json) \
    | php -r '$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(($result["installation"]["worktree_hooks"] ?? null) === [] ? 0 : 1);'
cp "${harbour_source}/tests/Fixtures/Orca/generated.yaml" "${project_root}/orca.yaml"

php -r '
$path = $argv[1]."/config/harbour.php";
$contents = file_get_contents($path);
$from = "env(\x27HARBOUR_ENABLED\x27, in_array(env(\x27APP_ENV\x27), [\x27local\x27, \x27testing\x27], true))";
if (!is_string($contents) || !str_contains($contents, $from)) {
    fwrite(STDERR, "Unable to enable the disposable Orca fixture.\n");
    exit(1);
}
file_put_contents($path, str_replace($from, "true", $contents));
' "${project_root}"
php -r '
$path = $argv[1]."/.env.harbour";
$contents = file_get_contents($path);
if (!is_string($contents)) {
    exit(1);
}
$contents = preg_replace("/^APP_NAME=.*$/m", "APP_NAME=HarbourAcceptance", $contents);
$contents = preg_replace("/^APP_KEY=.*$/m", "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", (string) $contents);
$contents = preg_replace("/^BROADCAST_CONNECTION=.*$/m", "BROADCAST_CONNECTION=log", (string) $contents);
file_put_contents($path, $contents);
' "${project_root}"

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
    if (is_file('.fail-setup')) {
        file_put_contents('.setup-failed', "injected setup failure\n");
        exit(22);
    }
    exit(0);
}
if ($action === 'teardown') {
    file_put_contents('.lifecycle.log', "teardown\n", FILE_APPEND);
    if (is_file('.fail-teardown')) {
        fwrite(STDERR, "injected teardown failure\n");
        exit(23);
    }
    exit(0);
}
exit(2);
PHP
printf '/.lifecycle.log\n/.setup-failed\n/.fail-setup\n/.fail-teardown\n' >>"${project_root}/.gitignore"

git -C "${project_root}" add --all
git -C "${project_root}" commit --quiet -m "Orca acceptance fixture"

repo_id="$("${orca_binary}" repo add --path "${project_root}" --json | json_value result.repo.id)"

create_worktree acceptance-a "${acceptance_root}/a.json" &
create_a_pid=$!
create_worktree acceptance-b "${acceptance_root}/b.json" &
create_b_pid=$!
wait "${create_a_pid}"
wait "${create_b_pid}"

workspace_a="$(json_value result.worktree.path <"${acceptance_root}/a.json")"
workspace_b="$(json_value result.worktree.path <"${acceptance_root}/b.json")"
workspace_container="$(dirname "${workspace_a}")"
created_worktrees+=("${workspace_a}" "${workspace_b}")
wait_for_file "${workspace_a}/.harbour.json"
wait_for_file "${workspace_b}/.harbour.json"
wait_for_file "${workspace_a}/.lifecycle.log"
wait_for_file "${workspace_b}/.lifecycle.log"
test "$(cat "${workspace_a}/.lifecycle.log")" = "setup"
test "$(cat "${workspace_b}/.lifecycle.log")" = "setup"

port_a="$(cd "${workspace_a}" && php artisan workspace:status --json | php -r '$s=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo $s["workspace"]["ports"]["APP_PORT"];')"
port_b="$(cd "${workspace_b}" && php artisan workspace:status --json | php -r '$s=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo $s["workspace"]["ports"]["APP_PORT"];')"
test "${port_a}" != "${port_b}"

(cd "${workspace_a}" && composer workspace:setup)
test "$(cat "${workspace_a}/.lifecycle.log")" = $'setup\nsetup'

touch "${workspace_a}/.fail-teardown"
if "${orca_binary}" worktree rm --worktree "path:${workspace_a}" --run-hooks --json; then
    echo "Orca removed a checkout after injected Harbour teardown failure." >&2
    exit 1
fi
test -d "${workspace_a}"
test -f "${workspace_a}/.harbour.json"

rm "${workspace_a}/.fail-teardown"
"${orca_binary}" worktree rm --worktree "path:${workspace_a}" --run-hooks --json
test ! -d "${workspace_a}"
test -f "${workspace_b}/.harbour.json"
(cd "${workspace_b}" && php artisan workspace:status --json) \
    | php -r '$s=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(($s["workspace"]["status"] ?? null) === "ready" ? 0 : 1);'
"${orca_binary}" worktree rm --worktree "path:${workspace_b}" --run-hooks --json
test ! -d "${workspace_b}"

git -C "${project_root}" switch --quiet -c injected-setup-failure
touch "${project_root}/.fail-setup"
git -C "${project_root}" add --force .fail-setup
git -C "${project_root}" commit --quiet -m "Inject setup failure"
git -C "${project_root}" switch --quiet main
create_worktree setup-failure "${acceptance_root}/setup-failure.json" --base-branch injected-setup-failure
workspace_failure="$(json_value result.worktree.path <"${acceptance_root}/setup-failure.json")"
created_worktrees+=("${workspace_failure}")
wait_for_file "${workspace_failure}/.setup-failed"
test -f "${workspace_failure}/.harbour.json"
"${orca_binary}" worktree rm --worktree "path:${workspace_failure}" --run-hooks --json
test ! -d "${workspace_failure}"

echo "Orca lifecycle probe passed; this runtime is a candidate for Harbour's validated support list."
