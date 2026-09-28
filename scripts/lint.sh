#!/usr/bin/env bash
# Syntax-checks every PHP and JS file in the plugin.
# Pinned in the agent allow list (.github/actions/claude-run/action.yml) so
# agent runs can verify their own changes without a wildcard php/node grant.
set -euo pipefail
cd "$(dirname "$0")/.."

status=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || { echo "PHP syntax error: $file"; status=1; }
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -print0)

if command -v node >/dev/null 2>&1; then
  while IFS= read -r -d '' file; do
    node --check "$file" || { echo "JS syntax error: $file"; status=1; }
  done < <(find . -name '*.js' -not -path './vendor/*' -not -path './node_modules/*' -print0)
fi

[[ $status -eq 0 ]] && echo "Lint OK"
exit $status
