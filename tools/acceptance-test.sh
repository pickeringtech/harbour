#!/usr/bin/env bash

set -euo pipefail

harbour_source="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
acceptance_root="$(mktemp -d "${TMPDIR:-/tmp}/harbour-acceptance-XXXXXXXX")"
project_root="${acceptance_root}/project"
workspace_a="${acceptance_root}/workspace-a"
workspace_b="${acceptance_root}/workspace-b"
workspace_failure="${acceptance_root}/workspace-failure"
dev_a=""
dev_b=""
reverb_a=""
reverb_b=""

cleanup() {
    for process in "${dev_a}" "${dev_b}" "${reverb_a}" "${reverb_b}"; do
        if [[ -n "${process}" ]]; then
            kill -- "-${process}" >/dev/null 2>&1 || true
        fi
    done
    for workspace in "${workspace_a}" "${workspace_b}" "${workspace_failure}"; do
        if [[ -f "${workspace}/artisan" && -f "${workspace}/.harbour.json" ]]; then
            (cd "${workspace}" && php artisan workspace:teardown --force) >/dev/null 2>&1 || true
        fi
    done
    if [[ -d "${project_root}/.git" ]]; then
        git -C "${project_root}" worktree remove --force "${workspace_a}" >/dev/null 2>&1 || true
        git -C "${project_root}" worktree remove --force "${workspace_b}" >/dev/null 2>&1 || true
        git -C "${project_root}" worktree remove --force "${workspace_failure}" >/dev/null 2>&1 || true
    fi
    case "${acceptance_root}" in
        "${TMPDIR:-/tmp}"/harbour-acceptance-*) rm -rf -- "${acceptance_root}" ;;
        *) echo "Refusing to remove unexpected acceptance path [${acceptance_root}]." >&2 ;;
    esac
}
trap cleanup EXIT

wait_for_file() {
    local path="$1"
    for _ in {1..60}; do
        [[ -s "${path}" ]] && return 0
        sleep 0.5
    done
    return 1
}

wait_for_port() {
    local port="$1"
    for _ in {1..60}; do
        php -r '$socket = @stream_socket_client("tcp://127.0.0.1:".$argv[1], $error, $message, 0.2); if (is_resource($socket)) { fclose($socket); exit(0); } exit(1);' "${port}" && return 0
        sleep 0.5
    done
    return 1
}

status_port() {
    php -r '$status = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $status["workspace"]["ports"][$argv[2]];' "$1" "$2"
}

export HARBOUR_STATE_HOME="${acceptance_root}/registry"
export HARBOUR_ACCEPTANCE_DOCKER="${HARBOUR_ACCEPTANCE_DOCKER:-0}"
export LARAVEL_BYPASS_ENV_CHECK=1

npm_security_options=()
npm_version_major="$(npm --version | cut -d. -f1)"
if (( npm_version_major >= 12 )); then
    npm_security_options+=(--allow-remote=all)
fi

composer create-project laravel/laravel:^13.0 "${project_root}" --no-interaction --prefer-dist
export DB_CONNECTION="${HARBOUR_ACCEPTANCE_DATABASE:-pgsql}"
export DB_HOST="${POSTGRES_HOST:-127.0.0.1}"
export DB_PORT="${POSTGRES_PORT:-5432}"
export DB_USERNAME="${POSTGRES_USER:-postgres}"
export DB_PASSWORD="${POSTGRES_PASSWORD:-harbour}"
export REDIS_CLIENT="${REDIS_CLIENT:-phpredis}"
export REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-6379}"
composer --working-dir="${project_root}" config repositories.harbour path "${harbour_source}"
composer --working-dir="${project_root}" require --dev pickeringtech/harbour:@dev --no-interaction
composer --working-dir="${project_root}" require laravel/reverb --with-all-dependencies --no-interaction
(cd "${project_root}" && php artisan vendor:publish --provider='Laravel\Reverb\ReverbServiceProvider' --tag=reverb-config --force --no-interaction)
if [[ "${REDIS_CLIENT}" == "predis" ]]; then
    composer --working-dir="${project_root}" require predis/predis --no-interaction
fi
npm --prefix "${project_root}" install --package-lock-only --ignore-scripts "${npm_security_options[@]}"
cp "${harbour_source}/tests/Fixtures/acceptance/detected.env" "${project_root}/.env"
(cd "${project_root}" && php artisan workspace:install --detect --json) \
    | php -r '$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); $selection = $result["installation"]["selection"] ?? []; exit(($result["installation"]["discovery"]["detected"] ?? false) && ($selection["database"] ?? null) === "pgsql" && ($selection["cache"] ?? null) === "redis" && ($selection["mail"] ?? null) === "mailpit" ? 0 : 1);'
cp "${harbour_source}/tests/Fixtures/acceptance/.env.harbour" "${project_root}/.env.harbour"
cp "${harbour_source}/tests/Fixtures/acceptance/harbour.php" "${project_root}/config/harbour.php"
cp "${harbour_source}/tests/Fixtures/acceptance/docker-compose.harbour.yml" "${project_root}/docker-compose.harbour.yml"
rm -f "${project_root}/.env"

git -C "${project_root}" init --initial-branch=main
git -C "${project_root}" config user.name "Harbour CI"
git -C "${project_root}" config user.email "harbour@example.invalid"
git -C "${project_root}" add --all
git -C "${project_root}" commit -m "Acceptance fixture"
git -C "${project_root}" worktree add -b feature/acceptance-a "${workspace_a}"
git -C "${project_root}" worktree add -b feature/acceptance-b "${workspace_b}"

(composer --working-dir="${workspace_a}" install --no-interaction >"${acceptance_root}/install-a.log" 2>&1) &
install_a=$!
(composer --working-dir="${workspace_b}" install --no-interaction >"${acceptance_root}/install-b.log" 2>&1) &
install_b=$!
wait "${install_a}" || { cat "${acceptance_root}/install-a.log"; exit 1; }
wait "${install_b}" || { cat "${acceptance_root}/install-b.log"; exit 1; }

(npm --prefix "${workspace_a}" ci "${npm_security_options[@]}" >"${acceptance_root}/npm-a.log" 2>&1) &
npm_a=$!
(npm --prefix "${workspace_b}" ci "${npm_security_options[@]}" >"${acceptance_root}/npm-b.log" 2>&1) &
npm_b=$!
wait "${npm_a}" || { cat "${acceptance_root}/npm-a.log"; exit 1; }
wait "${npm_b}" || { cat "${acceptance_root}/npm-b.log"; exit 1; }

(cd "${workspace_a}" && composer workspace:setup >"${acceptance_root}/setup-a.log" 2>&1) &
setup_a=$!
(cd "${workspace_b}" && composer workspace:setup >"${acceptance_root}/setup-b.log" 2>&1) &
setup_b=$!
wait "${setup_a}" || { cat "${acceptance_root}/setup-a.log"; exit 1; }
wait "${setup_b}" || { cat "${acceptance_root}/setup-b.log"; exit 1; }

(cd "${workspace_a}" && php artisan workspace:status --json) >"${acceptance_root}/status-a.json"
(cd "${workspace_b}" && php artisan workspace:status --json) >"${acceptance_root}/status-b.json"
vite_port_a="$(status_port "${acceptance_root}/status-a.json" VITE_PORT)"
vite_port_b="$(status_port "${acceptance_root}/status-b.json" VITE_PORT)"
app_port_a="$(status_port "${acceptance_root}/status-a.json" APP_PORT)"
app_port_b="$(status_port "${acceptance_root}/status-b.json" APP_PORT)"
reverb_port_a="$(status_port "${acceptance_root}/status-a.json" REVERB_PORT)"
reverb_port_b="$(status_port "${acceptance_root}/status-b.json" REVERB_PORT)"
compose_port_a="$(status_port "${acceptance_root}/status-a.json" ACCEPTANCE_COMPOSE_PORT)"
compose_port_b="$(status_port "${acceptance_root}/status-b.json" ACCEPTANCE_COMPOSE_PORT)"

setsid bash -c 'cd "$1" && exec composer workspace:dev' bash "${workspace_a}" >"${acceptance_root}/dev-a.log" 2>&1 &
dev_a=$!
setsid bash -c 'cd "$1" && exec composer workspace:dev' bash "${workspace_b}" >"${acceptance_root}/dev-b.log" 2>&1 &
dev_b=$!
setsid bash -c 'cd "$1" && exec php artisan reverb:start --host=127.0.0.1 --port="$2"' bash "${workspace_a}" "${reverb_port_a}" >"${acceptance_root}/reverb-a.log" 2>&1 &
reverb_a=$!
setsid bash -c 'cd "$1" && exec php artisan reverb:start --host=127.0.0.1 --port="$2"' bash "${workspace_b}" "${reverb_port_b}" >"${acceptance_root}/reverb-b.log" 2>&1 &
reverb_b=$!

wait_for_file "${workspace_a}/public/hot" || { cat "${acceptance_root}/dev-a.log"; exit 1; }
wait_for_file "${workspace_b}/public/hot" || { cat "${acceptance_root}/dev-b.log"; exit 1; }
wait_for_port "${app_port_a}" || { cat "${acceptance_root}/dev-a.log"; exit 1; }
wait_for_port "${app_port_b}" || { cat "${acceptance_root}/dev-b.log"; exit 1; }
wait_for_port "${reverb_port_a}" || { cat "${acceptance_root}/reverb-a.log"; exit 1; }
wait_for_port "${reverb_port_b}" || { cat "${acceptance_root}/reverb-b.log"; exit 1; }
wait_for_port "${compose_port_a}" || { cat "${acceptance_root}/dev-a.log"; exit 1; }
wait_for_port "${compose_port_b}" || { cat "${acceptance_root}/dev-b.log"; exit 1; }
grep -q ":${vite_port_a}" "${workspace_a}/public/hot"
grep -q ":${vite_port_b}" "${workspace_b}/public/hot"
test "$(cat "${workspace_a}/public/hot")" != "$(cat "${workspace_b}/public/hot")"
curl --fail --silent "http://127.0.0.1:${vite_port_a}/@vite/client" >/dev/null
curl --fail --silent "http://127.0.0.1:${vite_port_b}/@vite/client" >/dev/null
curl --fail --silent "http://127.0.0.1:${app_port_a}/up" >/dev/null
curl --fail --silent "http://127.0.0.1:${app_port_b}/up" >/dev/null

(php "${harbour_source}/tools/acceptance-probe.php" "${workspace_a}" workspace-a write >"${acceptance_root}/probe-a.json") &
probe_a=$!
(php "${harbour_source}/tools/acceptance-probe.php" "${workspace_b}" workspace-b write >"${acceptance_root}/probe-b.json") &
probe_b=$!
wait "${probe_a}"
wait "${probe_b}"
php "${harbour_source}/tools/verify-acceptance.php" \
    "${acceptance_root}/status-a.json" "${acceptance_root}/status-b.json" \
    "${acceptance_root}/probe-a.json" "${acceptance_root}/probe-b.json"

for process in "${dev_a}" "${dev_b}" "${reverb_a}" "${reverb_b}"; do
    kill -- "-${process}" >/dev/null 2>&1 || true
    wait "${process}" 2>/dev/null || true
done
dev_a=""
dev_b=""
reverb_a=""
reverb_b=""

php "${harbour_source}/tools/acceptance-probe.php" "${workspace_a}" workspace-a cleanup
(cd "${workspace_a}" && composer workspace:teardown -- --force)
test ! -e "${workspace_a}/.harbour.json"
test ! -e "${workspace_a}/.env"
php "${harbour_source}/tools/acceptance-probe.php" "${workspace_b}" workspace-b read \
    | php -r '$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(($result["cache"] ?? null) === "workspace-b" ? 0 : 1);'
php "${harbour_source}/tools/acceptance-probe.php" "${workspace_b}" workspace-b cleanup
(cd "${workspace_b}" && composer workspace:teardown -- --force)

git -C "${project_root}" worktree add -b feature/acceptance-failure "${workspace_failure}"
composer --working-dir="${workspace_failure}" install --no-interaction
if (cd "${workspace_failure}" && HARBOUR_ACCEPTANCE_FAIL=1 composer workspace:setup); then
    echo "Failure-injected setup unexpectedly succeeded." >&2
    exit 1
fi
(cd "${workspace_failure}" && php artisan workspace:status --json) \
    | php -r '$result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(($result["workspace"]["status"] ?? null) === "failed" ? 0 : 1);'
(cd "${workspace_failure}" && composer workspace:teardown -- --force)
test ! -e "${workspace_failure}/.harbour.json"
test ! -e "${workspace_failure}/.env"

echo "Harbour release acceptance passed, including two attached application sessions and partial-failure cleanup."
