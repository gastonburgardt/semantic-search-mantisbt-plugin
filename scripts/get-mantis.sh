#!/usr/bin/env bash
set -euo pipefail
VER="${1:-2.27.0}"
OUT_DIR="${2:-./vendor/mantisbt}"
URL="https://github.com/mantisbt/mantisbt/archive/refs/tags/release-${VER}.tar.gz"
mkdir -p "$OUT_DIR"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
curl -fsSL "$URL" -o "$TMP/mantisbt.tar.gz"
tar -xzf "$TMP/mantisbt.tar.gz" -C "$TMP"
SRC_DIR=$(find "$TMP" -maxdepth 1 -type d -name "mantisbt-release-*" | head -n1)
rm -rf "$OUT_DIR"/*
cp -R "$SRC_DIR"/* "$OUT_DIR"/
echo "MantisBT ${VER} downloaded to $OUT_DIR"
