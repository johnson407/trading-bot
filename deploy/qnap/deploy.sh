#!/bin/sh
set -eu

# QNAP Container Station keeps Docker outside the non-interactive PATH.
if ! command -v docker >/dev/null 2>&1; then
    for docker_dir in /share/CACHEDEV2_DATA/.qpkg/container-station/bin /usr/local/bin /usr/bin; do
        if [ -x "$docker_dir/docker" ]; then
            PATH="$docker_dir:$PATH"
            break
        fi
    done
fi
export PATH

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cd "$SCRIPT_DIR"

APP_IMAGE_REPO=${APP_IMAGE_REPO:-ghcr.io/johnson407/trading-bot}
NGINX_IMAGE_REPO=${NGINX_IMAGE_REPO:-ghcr.io/johnson407/trading-bot-nginx}
COMPOSE_FILE=${COMPOSE_FILE:-docker-compose.prod.yml}
STATE_DIR=${STATE_DIR:-$SCRIPT_DIR}
if [ -f "$STATE_DIR/.docker/config.json" ]; then
    DOCKER_CONFIG="$STATE_DIR/.docker"
    export DOCKER_CONFIG
fi
LOG_FILE=${LOG_FILE:-$SCRIPT_DIR/deploy.log}
DESIRED_FILE="$STATE_DIR/.desired-tag"
LAST_GOOD_FILE="$STATE_DIR/.last-good"
HOLD_FILE="$STATE_DIR/.hold"
LOCK_DIR="$STATE_DIR/.deploy.lock"
LOCK_FILE="$STATE_DIR/.deploy.lockfile"
HEALTH_TIMEOUT=${HEALTH_TIMEOUT:-180}
ROLLBACK_TIMEOUT=${ROLLBACK_TIMEOUT:-120}
DRY_RUN=${DRY_RUN:-0}
COMPOSE_KIND=""
TAG=""
MODE="normal"
TAG_ARG=""
CURRENT_APP_ID=""
CURRENT_NGINX_ID=""
FINAL_FAILED=""
FAILED_IMAGE_ID=""

APP_TIER="app nginx horizon scheduler"
INFRA_TIER="postgres redis"
SERVICES="$APP_TIER $INFRA_TIER"

usage() {
    cat <<'EOF'
Usage: ./deploy.sh [TAG | --use-latest | --clear-hold | --rollback]

  TAG             Pin and deploy a specific image tag (for example sha-<commit>).
  --use-latest    Clear the pin and hold, then deploy latest.
  --clear-hold    Retry the currently desired tag after a failed image.
  --rollback      Deploy the last fully healthy tag recorded in .last-good.

Environment:
  DRY_RUN=1       Print commands without changing state or starting containers.
  IMAGE_TAG=TAG   Pin a tag without an argument.
EOF
}

log() {
    line="$(date '+%Y-%m-%d %H:%M:%S') $*"
    printf '%s\n' "$line" >> "$LOG_FILE"
    printf '%s\n' "$line"
    cap_log
}

cap_log() {
    if [ -f "$LOG_FILE" ]; then
        size=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)
        if [ "$size" -gt 1048576 ]; then
            if tail -n 500 "$LOG_FILE" > "$LOG_FILE.tmp" 2>/dev/null; then
                mv "$LOG_FILE.tmp" "$LOG_FILE"
            else
                rm -f "$LOG_FILE.tmp"
            fi
        fi
    fi
}

acquire_lock() {
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$LOCK_FILE"
        if ! flock -n 9; then
            log "another deploy is already running; exiting"
            exit 0
        fi
        return 0
    fi

    if mkdir "$LOCK_DIR" 2>/dev/null; then
        printf '%s\n' "$$" > "$LOCK_DIR/pid"
        trap 'rm -rf "$LOCK_DIR"' EXIT HUP INT TERM
        return 0
    fi

    pid=$(cat "$LOCK_DIR/pid" 2>/dev/null || true)
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        log "another deploy is already running (pid $pid); exiting"
        exit 0
    fi

    rm -rf "$LOCK_DIR"
    if mkdir "$LOCK_DIR" 2>/dev/null; then
        printf '%s\n' "$$" > "$LOCK_DIR/pid"
        trap 'rm -rf "$LOCK_DIR"' EXIT HUP INT TERM
        return 0
    fi

    log "another deploy is already running; exiting"
    exit 0
}

detect_compose() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE_KIND="v2"
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE_KIND="v1"
    else
        log "neither 'docker compose' nor 'docker-compose' is available"
        exit 1
    fi
}

compose() {
    if [ "$COMPOSE_KIND" = "v2" ]; then
        docker compose -f "$COMPOSE_FILE" "$@"
    else
        docker-compose -f "$COMPOSE_FILE" "$@"
    fi
}

run() {
    if [ "$DRY_RUN" = "1" ]; then
        log "DRY-RUN: $*"
        return 0
    fi
    "$@"
}

read_desired() {
    if [ -s "$DESIRED_FILE" ]; then
        tr -d '\r\n' < "$DESIRED_FILE"
    else
        echo "latest"
    fi
}

write_desired() {
    printf '%s\n' "$1" > "$DESIRED_FILE"
}

read_last_good_tag() {
    [ -s "$LAST_GOOD_FILE" ] && sed -n '1p' "$LAST_GOOD_FILE" || true
}

read_last_good_app_id() {
    [ -s "$LAST_GOOD_FILE" ] && sed -n '2p' "$LAST_GOOD_FILE" || true
}

read_last_good_nginx_id() {
    [ -s "$LAST_GOOD_FILE" ] && sed -n '3p' "$LAST_GOOD_FILE" || true
}

validate_tag() {
    case "$1" in
        ''|*[!A-Za-z0-9_.-]*)
            log "invalid image tag: $1"
            exit 1
            ;;
    esac
}

container_image_id() {
    cid=$(compose ps -q "$1" 2>/dev/null | head -n 1 || true)
    [ -n "$cid" ] || return 0
    docker inspect --format '{{.Image}}' "$cid" 2>/dev/null || true
}

image_id_for() {
    docker image inspect --format '{{.Id}}' "$1" 2>/dev/null || true
}

repo_digest_for() {
    ref=$(docker image inspect --format '{{index .RepoDigests 0}}' "$1" 2>/dev/null || true)
    printf '%s\n' "${ref##*@}"
}

remote_repo_digest() {
    if docker buildx version >/dev/null 2>&1; then
        digest=$(docker buildx imagetools inspect --raw "$1" 2>/dev/null | sha256sum 2>/dev/null | awk '{print $1}')
        if [ -n "$digest" ]; then
            printf 'sha256:%s\n' "$digest"
        fi
    fi
}

all_healthy() {
    for svc in $SERVICES; do
        [ "$(health_of "$svc")" = "healthy" ] || return 1
    done
}

images_current() {
    [ -n "$CURRENT_APP_ID" ] && [ -n "$CURRENT_NGINX_ID" ] || return 1
    app_remote=$(remote_repo_digest "$APP_IMAGE_REPO:$TAG")
    nginx_remote=$(remote_repo_digest "$NGINX_IMAGE_REPO:$TAG")
    [ -n "$app_remote" ] && [ -n "$nginx_remote" ] || return 1
    [ "$app_remote" = "$(repo_digest_for "$APP_IMAGE_REPO:$TAG")" ] &&
        [ "$nginx_remote" = "$(repo_digest_for "$NGINX_IMAGE_REPO:$TAG")" ]
}

health_of() {
    cid=$(compose ps -q "$1" 2>/dev/null | head -n 1 || true)
    if [ -z "$cid" ]; then
        echo "missing"
        return 0
    fi
    docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || echo "unknown"
}

wait_for_health() {
    timeout=$1
    deadline=$(( $(date +%s) + timeout ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        failed=""
        for svc in $SERVICES; do
            state=$(health_of "$svc")
            if [ "$state" != "healthy" ]; then
                failed="$failed $svc:$state"
            fi
        done
        if [ -z "$failed" ]; then
            FINAL_FAILED=""
            return 0
        fi
        sleep 5
    done

    FINAL_FAILED=""
    for svc in $SERVICES; do
        state=$(health_of "$svc")
        if [ "$state" != "healthy" ]; then
            FINAL_FAILED="$FINAL_FAILED $svc:$state"
        fi
    done
    [ -z "$FINAL_FAILED" ]
}

is_infra_failure() {
    for item in $FINAL_FAILED; do
        case "$item" in
            postgres:*|redis:*)
                return 0
                ;;
        esac
    done
    return 1
}

pull_images() {
    if [ "$DRY_RUN" = "1" ]; then
        log "DRY-RUN: docker pull $APP_IMAGE_REPO:$TAG"
        log "DRY-RUN: docker pull $NGINX_IMAGE_REPO:$TAG"
        return 0
    fi

    if docker pull "$APP_IMAGE_REPO:$TAG" && docker pull "$NGINX_IMAGE_REPO:$TAG"; then
        return 0
    fi

    if [ "$MODE" = "rollback" ]; then
        app_id=$(read_last_good_app_id)
        nginx_id=$(read_last_good_nginx_id)
        if [ -n "$app_id" ] && [ -n "$nginx_id" ]; then
            log "registry tag $TAG is unavailable; using local last-good images"
            if docker image inspect "$app_id" >/dev/null 2>&1 && docker image inspect "$nginx_id" >/dev/null 2>&1; then
                docker tag "$app_id" "$APP_IMAGE_REPO:rollback"
                docker tag "$nginx_id" "$NGINX_IMAGE_REPO:rollback"
                TAG="rollback"
                return 0
            fi
        fi
    fi

    log "failed to pull $APP_IMAGE_REPO:$TAG or $NGINX_IMAGE_REPO:$TAG; run 'docker login ghcr.io' and check the tag"
    return 1
}

on_success() {
    revision=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$APP_IMAGE_REPO:$TAG" 2>/dev/null || true)
    app_id=$(image_id_for "$APP_IMAGE_REPO:$TAG")
    nginx_id=$(image_id_for "$NGINX_IMAGE_REPO:$TAG")

    case "$revision" in
        ''|'<no value>'|'<no value>'*)
            good_tag="$TAG"
            ;;
        *)
            good_tag="sha-$revision"
            ;;
    esac

    if [ -n "$good_tag" ] && [ "$good_tag" != "$TAG" ]; then
        docker tag "$APP_IMAGE_REPO:$TAG" "$APP_IMAGE_REPO:$good_tag"
        docker tag "$NGINX_IMAGE_REPO:$TAG" "$NGINX_IMAGE_REPO:$good_tag"
    fi

    printf '%s\n%s\n%s\n' "$good_tag" "$app_id" "$nginx_id" > "$LAST_GOOD_FILE"
    rm -f "$HOLD_FILE"
    docker image rm "$APP_IMAGE_REPO:rollback" >/dev/null 2>&1 || true
    docker image rm "$NGINX_IMAGE_REPO:rollback" >/dev/null 2>&1 || true
    docker image prune -f >/dev/null 2>&1 || true
    log "deployment successful: $good_tag"
}

on_failure() {
    reason=$1
    FAILED_IMAGE_ID=$(image_id_for "$APP_IMAGE_REPO:$TAG")

    if is_infra_failure; then
        log "infrastructure failure ($FINAL_FAILED); images were not rolled back; retrying on the next poll"
        exit 1
    fi

    log "application-tier failure ($FINAL_FAILED); rolling back"

    if [ -n "$CURRENT_APP_ID" ] && [ -n "$CURRENT_NGINX_ID" ]; then
        docker tag "$CURRENT_APP_ID" "$APP_IMAGE_REPO:rollback"
        docker tag "$CURRENT_NGINX_ID" "$NGINX_IMAGE_REPO:rollback"
        if compose up -d --pull never --remove-orphans; then
            if wait_for_health "$ROLLBACK_TIMEOUT"; then
                log "rollback is healthy"
            else
                log "rollback did not become healthy ($FINAL_FAILED)"
            fi
        else
            log "rollback compose up failed"
        fi
    else
        log "no previous application images are available; rollback skipped"
    fi

    if [ -n "$FAILED_IMAGE_ID" ]; then
        printf '%s\n' "$FAILED_IMAGE_ID" > "$HOLD_FILE"
    else
        printf '%s\n' "unknown" > "$HOLD_FILE"
    fi
    log "deployment failed ($reason); failed image is held; use --clear-hold or deploy a new image"
    exit 1
}

main() {
    for arg in "$@"; do
        case "$arg" in
            --use-latest)
                MODE="use-latest"
                ;;
            --clear-hold)
                MODE="clear-hold"
                ;;
            --rollback)
                MODE="rollback"
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            --*)
                log "unknown option: $arg"
                usage
                exit 1
                ;;
            *)
                if [ -n "$TAG_ARG" ]; then
                    log "only one tag argument is allowed"
                    exit 1
                fi
                TAG_ARG="$arg"
                ;;
        esac
    done

    if [ "$MODE" != "normal" ] && [ -n "$TAG_ARG" ]; then
        log "a tag argument cannot be combined with --$MODE"
        exit 1
    fi

    acquire_lock
    detect_compose

    case "$MODE" in
        use-latest)
            TAG="latest"
            if [ "$DRY_RUN" != "1" ]; then
                rm -f "$DESIRED_FILE" "$HOLD_FILE"
            fi
            ;;
        clear-hold)
            TAG=$(read_desired)
            if [ "$DRY_RUN" != "1" ]; then
                rm -f "$HOLD_FILE"
            fi
            ;;
        rollback)
            TAG=$(read_last_good_tag)
            if [ -z "$TAG" ]; then
                log "no last-good deployment is recorded; cannot roll back"
                exit 1
            fi
            if [ "$DRY_RUN" != "1" ]; then
                write_desired "$TAG"
            fi
            ;;
        *)
            if [ -n "$TAG_ARG" ]; then
                TAG="$TAG_ARG"
            elif [ -n "${IMAGE_TAG:-}" ]; then
                TAG="$IMAGE_TAG"
            else
                TAG=$(read_desired)
            fi
            if [ -n "$TAG_ARG" ] || [ -n "${IMAGE_TAG:-}" ]; then
                if [ "$DRY_RUN" != "1" ]; then
                    write_desired "$TAG"
                fi
            fi
            ;;
    esac

    validate_tag "$TAG"
    export IMAGE_TAG="$TAG"
    log "deploy start: desired=$TAG mode=$MODE dry_run=$DRY_RUN"

    if [ "$DRY_RUN" != "1" ]; then
        CURRENT_APP_ID=$(container_image_id app)
        CURRENT_NGINX_ID=$(container_image_id nginx)
        if images_current && all_healthy; then
            log "desired image $TAG is already running and healthy; nothing to do"
            exit 0
        fi
    fi

    if ! pull_images; then
        exit 1
    fi

    if [ "$DRY_RUN" = "1" ]; then
        log "DRY-RUN: compose up -d --remove-orphans (IMAGE_TAG=$TAG)"
        log "DRY-RUN: wait up to ${HEALTH_TIMEOUT}s for all six services to be healthy"
        log "DRY-RUN complete; no state changed"
        exit 0
    fi

    if [ -s "$HOLD_FILE" ]; then
        held_id=$(tr -d '\r\n' < "$HOLD_FILE")
        current_id=$(image_id_for "$APP_IMAGE_REPO:$TAG")
        if [ -n "$current_id" ] && [ "$held_id" = "$current_id" ]; then
            log "image $current_id is held after a previous failure; skipping. Use --clear-hold or deploy a new image."
            exit 0
        fi
        rm -f "$HOLD_FILE"
    fi

    export IMAGE_TAG="$TAG"
    log "activating $TAG"
    if ! compose up -d --remove-orphans; then
        FINAL_FAILED="compose-up"
        on_failure "compose-up"
    fi

    if ! wait_for_health "$HEALTH_TIMEOUT"; then
        on_failure "health"
    fi

    on_success
}

main "$@"
