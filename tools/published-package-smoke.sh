#!/usr/bin/env bash

set -euo pipefail

release_version="${1:-}"
if [[ ! "${release_version}" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Usage: $0 vMAJOR.MINOR.PATCH" >&2
    exit 2
fi
composer_version="${release_version#v}"
smoke_root="$(mktemp -d "${TMPDIR:-/tmp}/harbour-published-XXXXXXXX")"
application="${smoke_root}/application"
dev_pid=""

cleanup() {
    if [[ -n "${dev_pid}" ]]; then
        kill -- "-${dev_pid}" >/dev/null 2>&1 || true
        wait "${dev_pid}" >/dev/null 2>&1 || true
    fi
    if [[ -f "${application}/artisan" && -f "${application}/.harbour.json" ]]; then
        (cd "${application}" && php artisan workspace:teardown --force) >/dev/null 2>&1 || true
    fi
    case "${smoke_root}" in
        "${TMPDIR:-/tmp}"/harbour-published-*) rm -rf -- "${smoke_root}" ;;
        *) echo "Refusing to remove unexpected smoke-test path [${smoke_root}]." >&2 ;;
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
sha256sum "${application}/.env" | cut -d ' ' -f 1 > "${smoke_root}/original-env.sha256"

installed=false
for attempt in {1..10}; do
    if composer --working-dir="${application}" require --dev "pickeringtech/harbour:${composer_version}" --no-interaction --prefer-dist --no-progress; then
        installed=true
        break
    fi
    echo "Packagist has not exposed ${composer_version} yet (attempt ${attempt}/10)." >&2
    sleep 15
done
if [[ "${installed}" != true ]]; then
    echo "Unable to install pickeringtech/harbour:${composer_version} from Packagist." >&2
    exit 1
fi

php -r '
$lock = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
foreach (array_merge($lock["packages"] ?? [], $lock["packages-dev"] ?? []) as $package) {
    if (($package["name"] ?? null) !== "pickeringtech/harbour") {
        continue;
    }
    $actual = ltrim((string) ($package["version"] ?? ""), "v");
    $dist = $package["dist"]["url"] ?? null;
    exit($actual === $argv[2] && is_string($dist) && $dist !== "" ? 0 : 1);
}
exit(1);
' "${application}/composer.lock" "${composer_version}"

(cd "${application}" && php artisan workspace:install \
    --database=sqlite \
    --cache=file \
    --mail=log \
    --with=none \
    --provider=shared \
    --start \
    --no-interaction \
    --json) > "${smoke_root}/install.json"
php -r '$result = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); exit(($result["ok"] ?? false) && ($result["installation"]["started"] ?? false) ? 0 : 1);' "${smoke_root}/install.json"

(cd "${application}" && LARAVEL_BYPASS_ENV_CHECK=1 setsid composer workspace:dev) > "${smoke_root}/dev.log" 2>&1 &
dev_pid=$!
app_port="$(state_port APP_PORT)"
vite_port="$(state_port VITE_PORT)"
if ! wait_for_port "${app_port}" || ! wait_for_port "${vite_port}"; then
    sed -n '1,240p' "${smoke_root}/dev.log" >&2
    exit 1
fi
if ! wait_for_http "http://127.0.0.1:${app_port}"; then
    sed -n '1,240p' "${smoke_root}/dev.log" >&2
    for log in "${application}"/storage/logs/*.log; do
        [[ -f "${log}" ]] && tail -n 120 "${log}" >&2
    done
    exit 1
fi

kill -- "-${dev_pid}" >/dev/null 2>&1 || true
wait "${dev_pid}" >/dev/null 2>&1 || true
dev_pid=""

(if cd "${application}" && php artisan list --raw | grep -q '^workspace:uninstall '; then
    composer workspace:uninstall -- --force
    test ! -e .env.harbour
    test ! -e config/harbour.php
else
    composer workspace:teardown -- --force
fi)
test ! -e "${application}/.harbour.json"
test "$(sha256sum "${application}/.env" | cut -d ' ' -f 1)" = "$(<"${smoke_root}/original-env.sha256")"

echo "Published package ${release_version} passed fresh Laravel install, launch, and uninstall smoke testing."
