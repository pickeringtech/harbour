#!/usr/bin/env bash

set -euo pipefail

harbour_source="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
acceptance_root="$(mktemp -d "${TMPDIR:-/tmp}/harbour-acceptance-XXXXXXXX")"
project_root="${acceptance_root}/project"
workspace_a="${acceptance_root}/workspace-a"
workspace_b="${acceptance_root}/workspace-b"
workspace_failure="${acceptance_root}/workspace-failure"

cleanup() {
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

export HARBOUR_STATE_HOME="${acceptance_root}/registry"
export HARBOUR_ACCEPTANCE_DOCKER="${HARBOUR_ACCEPTANCE_DOCKER:-0}"
export DB_CONNECTION="${HARBOUR_ACCEPTANCE_DATABASE:-pgsql}"
export DB_HOST="${POSTGRES_HOST:-127.0.0.1}"
export DB_PORT="${POSTGRES_PORT:-5432}"
export DB_USERNAME="${POSTGRES_USER:-postgres}"
export DB_PASSWORD="${POSTGRES_PASSWORD:-harbour}"
export REDIS_CLIENT="${REDIS_CLIENT:-phpredis}"
export REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-6379}"

composer create-project laravel/laravel:^13.0 "${project_root}" --no-interaction --prefer-dist
composer --working-dir="${project_root}" config repositories.harbour path "${harbour_source}"
composer --working-dir="${project_root}" require --dev pickeringtech/harbour:@dev --no-interaction
if [[ "${REDIS_CLIENT}" == "predis" ]]; then
    composer --working-dir="${project_root}" require predis/predis --no-interaction
fi
(cd "${project_root}" && php artisan workspace:install --json)
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

(cd "${workspace_a}" && composer workspace:setup >"${acceptance_root}/setup-a.log" 2>&1) &
setup_a=$!
(cd "${workspace_b}" && composer workspace:setup >"${acceptance_root}/setup-b.log" 2>&1) &
setup_b=$!
wait "${setup_a}" || { cat "${acceptance_root}/setup-a.log"; exit 1; }
wait "${setup_b}" || { cat "${acceptance_root}/setup-b.log"; exit 1; }

(cd "${workspace_a}" && php artisan workspace:status --json) >"${acceptance_root}/status-a.json"
(cd "${workspace_b}" && php artisan workspace:status --json) >"${acceptance_root}/status-b.json"
(php "${harbour_source}/tools/acceptance-probe.php" "${workspace_a}" workspace-a write >"${acceptance_root}/probe-a.json") &
probe_a=$!
(php "${harbour_source}/tools/acceptance-probe.php" "${workspace_b}" workspace-b write >"${acceptance_root}/probe-b.json") &
probe_b=$!
wait "${probe_a}"
wait "${probe_b}"
php "${harbour_source}/tools/verify-acceptance.php" \
    "${acceptance_root}/status-a.json" "${acceptance_root}/status-b.json" \
    "${acceptance_root}/probe-a.json" "${acceptance_root}/probe-b.json"

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

echo "Harbour release acceptance passed, including partial-failure cleanup."
