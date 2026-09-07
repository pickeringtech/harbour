#!/usr/bin/env bash

set -euo pipefail

previous_version="${1:-}"
harbour_source="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
if [[ ! "${previous_version}" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Usage: $0 vMAJOR.MINOR.PATCH [candidate-source]" >&2
    exit 2
fi
upgrade_root="$(mktemp -d "${TMPDIR:-/tmp}/harbour-upgrade-XXXXXXXX")"
application="${upgrade_root}/application"
dev_pid=""

cleanup() {
    if [[ -n "${dev_pid}" ]]; then
        kill -- "-${dev_pid}" >/dev/null 2>&1 || true
        wait "${dev_pid}" >/dev/null 2>&1 || true
    fi
    if [[ -f "${application}/artisan" && -f "${application}/.harbour.json" ]]; then
        (cd "${application}" && php artisan workspace:teardown --force) >/dev/null 2>&1 || true
    fi
    case "${upgrade_root}" in
        "${TMPDIR:-/tmp}"/harbour-upgrade-*) rm -rf -- "${upgrade_root}" ;;
        *) echo "Refusing to remove unexpected upgrade-test path [${upgrade_root}]." >&2 ;;
    esac
}
trap cleanup EXIT

wait_for_port() {
    local port="$1"
    for _ in {1..120}; do
        php -r '$socket = @stream_socket_client("tcp://127.0.0.1:".$argv[1], $error, $message, 0.2); if (is_resource($socket)) { fclose($socket); exit(0); } exit(1);' "${port}" && return 0
        if ! kill -0 "${dev_pid}" >/dev/null 2>&1; then
            return 1
        fi
        sleep 0.5
    done
    return 1
}

wait_for_http() {
    local url="$1"
    for _ in {1..60}; do
        curl --fail --silent --show-error "${url}" >/dev/null 2>&1 && return 0
        if ! kill -0 "${dev_pid}" >/dev/null 2>&1; then
            return 1
        fi
        sleep 0.5
    done
    return 1
}

state_port() {
    php -r '$state = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $state["allocations"][$argv[2]];' "${application}/.harbour.json" "$1"
}

composer create-project laravel/laravel:^13.0 "${application}" --no-interaction --prefer-dist --no-progress
sha256sum "${application}/.env" | cut -d ' ' -f 1 > "${upgrade_root}/original-env.sha256"
composer --working-dir="${application}" require --dev "pickeringtech/harbour:${previous_version#v}" --no-interaction --prefer-dist --no-progress
(cd "${application}" && php artisan workspace:install \
    --database=sqlite \
    --cache=file \
    --mail=log \
    --with=none \
    --provider=shared \
    --start \
    --no-interaction \
    --json) > "${upgrade_root}/previous-install.json"
sha256sum "${application}/.env.harbour" | cut -d ' ' -f 1 > "${upgrade_root}/template.sha256"
sha256sum "${application}/config/harbour.php" | cut -d ' ' -f 1 > "${upgrade_root}/config.sha256"
cp "${application}/.env" "${upgrade_root}/rendered.env"

composer --working-dir="${application}" config repositories.harbour path "${harbour_source}"
composer --working-dir="${application}" require --dev pickeringtech/harbour:@dev --with-all-dependencies --no-interaction --no-progress

(cd "${application}" && php artisan workspace:status --json) \
    | php -r '$status = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(($status["workspace"]["status"] ?? null) === "ready" ? 0 : 1);'
(cd "${application}" && php artisan workspace:install \
    --database=sqlite \
    --cache=file \
    --mail=log \
    --with=none \
    --provider=shared \
    --no-interaction \
    --json) > "${upgrade_root}/candidate-install.json"
test "$(sha256sum "${application}/.env.harbour" | cut -d ' ' -f 1)" = "$(<"${upgrade_root}/template.sha256")"
test "$(sha256sum "${application}/config/harbour.php" | cut -d ' ' -f 1)" = "$(<"${upgrade_root}/config.sha256")"
(cd "${application}" && composer workspace:setup)

(cd "${application}" && setsid composer workspace:dev) > "${upgrade_root}/dev.log" 2>&1 &
dev_pid=$!
app_port="$(state_port APP_PORT)"
vite_port="$(state_port VITE_PORT)"
if ! wait_for_port "${app_port}" || ! wait_for_port "${vite_port}" || ! wait_for_http "http://127.0.0.1:${app_port}"; then
    sed -n '1,240p' "${upgrade_root}/dev.log" >&2
    exit 1
fi
kill -- "-${dev_pid}" >/dev/null 2>&1 || true
wait "${dev_pid}" >/dev/null 2>&1 || true
dev_pid=""

printf '\nMANUAL_UPGRADE_CHECK=true\n' >> "${application}/.env"
if (cd "${application}" && php artisan workspace:setup --json) > "${upgrade_root}/protected-failure.json"; then
    echo "Candidate setup unexpectedly replaced a modified environment." >&2
    exit 1
fi
grep -q 'HARBOUR_ENVIRONMENT_MODIFIED' "${upgrade_root}/protected-failure.json"
(cd "${application}" && php artisan workspace:status --json) \
    | php -r '$status = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); exit(($status["workspace"]["status"] ?? null) === "ready" ? 0 : 1);'
cp "${upgrade_root}/rendered.env" "${application}/.env"

(cd "${application}" && composer workspace:uninstall -- --force)
test ! -e "${application}/.harbour.json"
test ! -e "${application}/.env.harbour"
test ! -e "${application}/config/harbour.php"
test "$(sha256sum "${application}/.env" | cut -d ' ' -f 1)" = "$(<"${upgrade_root}/original-env.sha256")"

echo "Upgrade from ${previous_version} to the candidate passed state, policy, failure-recovery, and uninstall checks."
