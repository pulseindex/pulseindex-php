#!/usr/bin/env bash
# Regenerate the PHP protobuf and gRPC stubs from proto/engine.proto.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROTO_DIR="${ROOT}/proto"
OUT_DIR="${ROOT}/generated"
PROTO_FILE="${PROTO_DIR}/engine.proto"

# Syncing from another copy is an explicit act: pass the source path, then
# review the result by hand before committing.
#   PULSEINDEX_PROTO=/path/to/engine.proto scripts/compile-proto.sh --sync
SOURCE_PROTO=""
if [[ "${1:-}" == "--sync" ]]; then
  SOURCE_PROTO="${PULSEINDEX_PROTO:-}"
  if [[ -z "${SOURCE_PROTO}" ]]; then
    echo "error: --sync needs PULSEINDEX_PROTO pointing at the source proto" >&2
    exit 1
  fi
  echo "WARNING: overwriting proto/engine.proto. Review it before committing." >&2
fi

mkdir -p "${PROTO_DIR}" "${OUT_DIR}"

if [[ -n "${SOURCE_PROTO}" && -f "${SOURCE_PROTO}" ]]; then
  echo "Copying proto from ${SOURCE_PROTO}"
  cp "${SOURCE_PROTO}" "${PROTO_FILE}"
else
  echo "Using vendored ${PROTO_FILE}"
fi

if ! grep -q 'option php_namespace' "${PROTO_FILE}"; then
  # Insert PHP namespace options after the package declaration.
  tmp="$(mktemp)"
  awk '
    BEGIN { inserted=0 }
    {
      print
      if (!inserted && $0 ~ /^package pulseindex\.engine\.v1;/) {
        print ""
        print "option php_namespace = \"PulseIndex\\\\Engine\\\\V1\";"
        print "option php_metadata_namespace = \"GPBMetadata\\\\PulseIndex\";"
        inserted=1
      }
    }
  ' "${PROTO_FILE}" > "${tmp}"
  mv "${tmp}" "${PROTO_FILE}"
fi

command -v protoc >/dev/null 2>&1 || {
  echo "error: protoc is required" >&2
  exit 1
}

# Refuse rather than write a client that contradicts the proto beside it.
#
# Checked before the wipe below, so a missing plugin leaves the committed
# client in place. There is no fallback: a generator that half-works is worse
# than one that stops.
if ! command -v grpc_php_plugin >/dev/null 2>&1; then
  cat >&2 <<'MSG'
error: grpc_php_plugin is required and was not found on PATH.

  macOS:  brew install grpc
  Linux:  build it from grpc/grpc, or use a distro package that ships it
MSG
  exit 1
fi

rm -rf "${OUT_DIR}/PulseIndex" "${OUT_DIR}/GPBMetadata"
mkdir -p "${OUT_DIR}"

echo "Generating PHP message classes..."
protoc \
  --php_out="${OUT_DIR}" \
  -I "${PROTO_DIR}" \
  "${PROTO_FILE}"

# health.proto too: the wipe above removes all of GPBMetadata, including the
# class the health client needs.
HEALTH_PROTO="${PROTO_DIR}/health.proto"
if [[ -f "${HEALTH_PROTO}" ]]; then
  echo "Generating PHP health classes..."
  protoc \
    --php_out="${OUT_DIR}" \
    -I "${PROTO_DIR}" \
    "${HEALTH_PROTO}"
else
  echo "error: ${HEALTH_PROTO} is missing; the health client cannot be generated" >&2
  exit 1
fi

echo "Generating gRPC client via grpc_php_plugin..."
protoc \
  --plugin=protoc-gen-grpc="$(command -v grpc_php_plugin)" \
  --grpc_out="${OUT_DIR}" \
  -I "${PROTO_DIR}" \
  "${PROTO_FILE}"

# Record a normalised hash so scripts/check-proto.php can detect an edit to the
# proto that was not followed by regenerating the stubs. Comments are stripped
# from the hash basis, because they do not reach the stubs.
sed -E 's://.*::' "${PROTO_FILE}" \
  | grep -vE '^[[:space:]]*(option php_|$)' \
  | shasum -a 256 | awk '{print $1}' \
  > "${PROTO_DIR}/engine.proto.sha256"

echo "Proto compile complete → ${OUT_DIR}"
echo "Baseline hash → ${PROTO_DIR}/engine.proto.sha256"
