# The host has no Go toolchain: every target runs through the official Docker
# image. Keep GO_VERSION in sync with the Dockerfile build stage.
GO_VERSION ?= 1.24
GO_IMAGE ?= golang:$(GO_VERSION)-alpine
DOCKER_RUN = docker run --rm -v "$(CURDIR):/src" -w /src -e GOCACHE=/tmp/go-cache -e GOMODCACHE=/tmp/go-mod $(GO_IMAGE)

.PHONY: test fmt vet build tidy

## test: required gate for every Go phase; exits non-zero on failure.
test:
	$(DOCKER_RUN) go test ./...

## vet: run go vet in the same pinned toolchain.
vet:
	$(DOCKER_RUN) go vet ./...

## fmt: print files that need gofmt.
fmt:
	$(DOCKER_RUN) gofmt -l .

## tidy: refresh go.mod and go.sum after dependency changes.
tidy:
	$(DOCKER_RUN) go mod tidy

## build: build the runtime image used by the local Compose engine service.
build:
	docker build -t trading-bot-engine .
