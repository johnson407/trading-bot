#!/bin/sh
set -u

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
SCRIPT="$ROOT/deploy/qnap/deploy.sh"
TMP=$(mktemp -d /tmp/opencode/qnap-deploy-test.XXXXXX)
trap 'rm -rf "$TMP"' EXIT HUP INT TERM

PASS=0
FAIL=0
CASE=""

pass() {
    PASS=$((PASS + 1))
    printf 'PASS %s\n' "$1"
}

fail() {
    FAIL=$((FAIL + 1))
    printf 'FAIL %s\n' "$1"
}

check() {
    description=$1
    shift
    if "$@"; then
        pass "$description"
    else
        fail "$description"
    fi
}

assert_eq() {
    [ "$1" = "$2" ]
}

assert_file_contains() {
    [ -f "$1" ] && grep -Fq -- "$2" "$1"
}

assert_file_absent() {
    [ ! -e "$1" ]
}

mkdir -p "$TMP/bin"
cat > "$TMP/bin/docker" <<'STUB'
#!/bin/sh
printf 'docker %s\n' "$*" >> "${STUB_LOG:-/dev/null}"
cmd=${1:-}
shift || true

case "$cmd" in
    compose)
        if [ "${1:-}" = "-f" ]; then
            shift 2
        fi
        sub=${1:-}
        shift || true
        case "$sub" in
            version)
                exit 0
                ;;
            ps)
                if [ "${1:-}" = "-q" ]; then
                    shift
                fi
                svc=${1:-}
                if [ -f "$STUB_DIR/up" ] || [ "${STUB_PREVIOUS:-0}" = "1" ]; then
                    printf 'cid-%s\n' "$svc"
                fi
                exit 0
                ;;
            up)
                touch "$STUB_DIR/up"
                exit 0
                ;;
        esac
        ;;
    pull)
        if [ "${STUB_PULL_FAIL:-0}" = "1" ]; then
            exit 1
        fi
        exit 0
        ;;
    inspect)
        if [ "${1:-}" = "--format" ]; then
            shift
        fi
        fmt=${1:-}
        shift || true
        cid=${1:-}
        svc=${cid#cid-}
        case "$fmt" in
            *opencontainers.image.revision*)
                if [ "${STUB_NO_REVISION:-0}" = "1" ]; then
                    echo "<no value>"
                else
                    echo "${STUB_REVISION:-abc123}"
                fi
                ;;
            *State.Health*)
                case " ${STUB_UNHEALTHY:-} " in
                    *" $svc "*)
                        echo "unhealthy"
                        ;;
                    *)
                        echo "healthy"
                        ;;
                esac
                ;;
            *'.Image'*)
                echo "${STUB_PREV_IMAGE:-sha256:prev-$svc}"
                ;;
            *)
                echo "healthy"
                ;;
        esac
        exit 0
        ;;
    image)
        sub=${1:-}
        shift || true
        case "$sub" in
            inspect)
                if [ "${1:-}" = "--format" ]; then
                    shift
                fi
                fmt=${1:-}
                shift || true
                image=${1:-}
                case "$image" in
                    *trading-bot-nginx*)
                        default_id="${STUB_NGINX_ID:-sha256:new-nginx}"
                        ;;
                    *)
                        default_id="${STUB_APP_ID:-sha256:new-app}"
                        ;;
                esac
                case "$fmt" in
                    *opencontainers.image.revision*)
                        if [ "${STUB_NO_REVISION:-0}" = "1" ]; then
                            echo "<no value>"
                        else
                            echo "${STUB_REVISION:-abc123}"
                        fi
                        ;;
                    *'.Id'*)
                        echo "$default_id"
                        ;;
                    *)
                        echo "$default_id"
                        ;;
                esac
                exit 0
                ;;
            rm|prune)
                exit 0
                ;;
        esac
        ;;
    tag)
        exit 0
        ;;
esac

exit 0
STUB
chmod +x "$TMP/bin/docker"

reset_env() {
    unset STUB_UNHEALTHY STUB_PREVIOUS STUB_APP_ID STUB_NGINX_ID STUB_PULL_FAIL STUB_NO_REVISION STUB_REVISION DRY_RUN 2>/dev/null || true
}

new_case() {
    reset_env
    CASE=$(mktemp -d "$TMP/case.XXXXXX")
    cp "$SCRIPT" "$CASE/deploy.sh"
    chmod +x "$CASE/deploy.sh"
    : > "$CASE/docker-compose.prod.yml"
    rm -f "$CASE/up"
}

run_deploy() {
    (
        cd "$CASE" || exit 1
        export PATH="$TMP/bin:$PATH"
        export STUB_DIR="$CASE"
        export STUB_LOG="$CASE/docker.log"
        export STATE_DIR="$CASE"
        export LOG_FILE="$CASE/deploy.log"
        export COMPOSE_FILE="$CASE/docker-compose.prod.yml"
        export HEALTH_TIMEOUT=0
        export ROLLBACK_TIMEOUT=0
        "$CASE/deploy.sh" "$@"
    )
}

# First deploy succeeds and records immutable state.
new_case
run_deploy
check "first deploy succeeds" test -s "$CASE/.last-good"
check "last-good tag is immutable" assert_eq "$(sed -n '1p' "$CASE/.last-good")" "sha-abc123"
check "last-good app id recorded" assert_eq "$(sed -n '2p' "$CASE/.last-good")" "sha256:new-app"
check "last-good nginx id recorded" assert_eq "$(sed -n '3p' "$CASE/.last-good")" "sha256:new-nginx"
check "success is logged" assert_file_contains "$CASE/deploy.log" "deployment successful: sha-abc123"

# A failed image is held and skipped on the next poll.
new_case
export STUB_UNHEALTHY="app"
if run_deploy; then rc=0; else rc=$?; fi
check "application failure exits non-zero" test "$rc" -ne 0
check "failed image is held" test -s "$CASE/.hold"
held=$(cat "$CASE/.hold")
rm -f "$CASE/up"
unset STUB_UNHEALTHY
run_deploy
check "held image is skipped" assert_file_contains "$CASE/deploy.log" "is held after a previous failure; skipping"
check "held image does not activate" assert_file_absent "$CASE/up"
check "held image remains held" assert_eq "$(cat "$CASE/.hold")" "$held"

# A different image clears the old hold.
new_case
printf '%s\n' "sha256:old-app" > "$CASE/.hold"
export STUB_APP_ID="sha256:new-app"
run_deploy
check "new image clears the hold" assert_file_absent "$CASE/.hold"
check "new image deploys" assert_file_contains "$CASE/deploy.log" "deployment successful: sha-abc123"

# A manual pin survives a later poll with no arguments.
new_case
run_deploy sha-pin1
check "pin is written" assert_eq "$(cat "$CASE/.desired-tag")" "sha-pin1"
run_deploy
check "pin is reused" assert_file_contains "$CASE/deploy.log" "desired=sha-pin1 mode=normal"

# --use-latest clears the pin and hold.
new_case
printf '%s\n' "sha-pin1" > "$CASE/.desired-tag"
printf '%s\n' "sha256:held" > "$CASE/.hold"
run_deploy --use-latest
check "--use-latest clears the pin" assert_file_absent "$CASE/.desired-tag"
check "--use-latest clears the hold" assert_file_absent "$CASE/.hold"
check "--use-latest deploys latest" assert_file_contains "$CASE/deploy.log" "activating latest"

# --clear-hold retries the desired tag.
new_case
printf '%s\n' "latest" > "$CASE/.desired-tag"
printf '%s\n' "sha256:held" > "$CASE/.hold"
run_deploy --clear-hold
check "--clear-hold removes the hold" assert_file_absent "$CASE/.hold"
check "--clear-hold activates the desired tag" assert_file_contains "$CASE/deploy.log" "activating latest"

# --rollback uses the last-good tag.
new_case
printf 'sha-old\nsha256:old-app\nsha256:old-nginx\n' > "$CASE/.last-good"
run_deploy --rollback
check "--rollback records the desired tag" assert_eq "$(cat "$CASE/.desired-tag")" "sha-old"
check "--rollback activates last-good" assert_file_contains "$CASE/deploy.log" "activating sha-old"

# --rollback falls back to local last-good images when the registry is unavailable.
new_case
printf 'sha-old\nsha256:old-app\nsha256:old-nginx\n' > "$CASE/.last-good"
export STUB_PULL_FAIL=1
run_deploy --rollback
check "rollback uses local images" assert_file_contains "$CASE/deploy.log" "using local last-good images"
check "rollback activates the local rollback tag" assert_file_contains "$CASE/deploy.log" "activating rollback"

# Infrastructure failure does not roll back or hold the image.
new_case
export STUB_PREVIOUS=1
export STUB_UNHEALTHY="postgres"
if run_deploy; then rc=0; else rc=$?; fi
check "infrastructure failure exits non-zero" test "$rc" -ne 0
check "infrastructure failure is classified" assert_file_contains "$CASE/deploy.log" "infrastructure failure"
check "infrastructure failure does not hold" assert_file_absent "$CASE/.hold"
check "infrastructure failure does not write last-good" assert_file_absent "$CASE/.last-good"

# Application failure rolls back the previous images and records the hold.
new_case
export STUB_PREVIOUS=1
export STUB_UNHEALTHY="app"
if run_deploy; then rc=0; else rc=$?; fi
check "application failure exits non-zero" test "$rc" -ne 0
check "application failure is classified" assert_file_contains "$CASE/deploy.log" "application-tier failure"
check "rollback retags previous app image" assert_file_contains "$CASE/docker.log" "tag sha256:prev-app ghcr.io/johnson407/trading-bot:rollback"
check "rollback starts without pulling" assert_file_contains "$CASE/docker.log" "up -d --pull never --remove-orphans"
check "application failure holds the image" assert_eq "$(cat "$CASE/.hold")" "sha256:new-app"
check "failed deployment does not become last-good" assert_file_absent "$CASE/.last-good"

# A first deploy has no previous image to restore.
new_case
export STUB_UNHEALTHY="app"
if run_deploy; then rc=0; else rc=$?; fi
check "first failure has no rollback image" assert_file_contains "$CASE/deploy.log" "no previous application images are available"
check "first failure still holds the image" test -s "$CASE/.hold"

# Dry run does not change state.
new_case
export DRY_RUN=1
run_deploy sha-dry
check "dry run does not write the pin" assert_file_absent "$CASE/.desired-tag"
check "dry run does not write last-good" assert_file_absent "$CASE/.last-good"
check "dry run prints pull commands" assert_file_contains "$CASE/deploy.log" "DRY-RUN: docker pull ghcr.io/johnson407/trading-bot:sha-dry"
check "dry run does not activate" assert_file_absent "$CASE/up"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
