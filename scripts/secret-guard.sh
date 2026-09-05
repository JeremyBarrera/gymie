#!/usr/bin/env bash
set -euo pipefail
fail=0
allow='^$|^(null|test|changeme.*|your-.*|.*example.*|reverb(-key|-secret)?|\$.*)$'
while IFS= read -r f; do
  [ -z "$f" ] && continue
  if grep -qEi '[a-z0-9-]+\.ts\.net' "$f"; then
    echo "secret-guard: tailnet hostname in $f"
    fail=1
  fi
  if grep -qEi '@(gmail|yahoo|hotmail|outlook|icloud|protonmail|gmx)\.[a-z]+' "$f"; then
    echo "secret-guard: freemail address in $f"
    fail=1
  fi
  while IFS= read -r line; do
    [ -z "$line" ] && continue
    value="$(printf '%s\n' "${line#*=}" | tr -d ' "\r' | tr -d "'" | tr '[:upper:]' '[:lower:]')"
    if printf '%s\n' "$value" | grep -qEi "$allow"; then
      continue
    fi
    echo "secret-guard: non-placeholder secret in $f: ${line%%=*}"
    fail=1
  done < <(grep -Ei '(password|secret|token|_key)[[:space:]]*=' "$f" || true)
done < <(git ls-files '*.example')
if [ "$fail" -ne 0 ]; then
  echo "secret-guard: FAILED"
  exit 1
fi
echo "secret-guard: OK"
