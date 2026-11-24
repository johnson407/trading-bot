# syntax=docker/dockerfile:1

# Keep this tag in sync with GO_VERSION in engine/Makefile.
FROM golang:1.24-alpine AS build

WORKDIR /src

COPY go.mod go.sum ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/engine .

FROM gcr.io/distroless/static:nonroot

COPY --from=build /out/engine /engine

# distroless static:nonroot runs as uid 65532.
USER nonroot:nonroot

EXPOSE 8080

# `engine healthcheck` probes GET /healthz inside the container. No exchange.
HEALTHCHECK --interval=10s --timeout=5s --start-period=5s --retries=6 CMD ["/engine", "healthcheck"]

ENTRYPOINT ["/engine"]
