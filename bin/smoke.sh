#!/usr/bin/env bash
# End to end against the DDEV site: free tool, paid tool unpaid (402), paid tool paid (200 + receipt).
set -euo pipefail
cd "$(dirname "$0")/.."
U=${MCP_URL:-https://quebracho-x402-mcp.ddev.site/mcp}
c(){ curl -sSk --http1.1 -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' "$@"; }
S=$(c -D - -o /dev/null "$U" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' | grep -i '^mcp-session-id' | cut -d' ' -f2 | tr -d '\r')
c -H "Mcp-Session-Id: $S" "$U" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' -o /dev/null
CALL='{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"wisdom","arguments":{}}}'

echo "== echo (free)"
c -H "Mcp-Session-Id: $S" "$U" -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"hola hackathon"}}}'; echo

echo "== wisdom, unpaid"
REQUIRED=$(c -D - -o /dev/null -H "Mcp-Session-Id: $S" "$U" -d "$CALL" | tee /dev/stderr | grep -i '^payment-required' | cut -d' ' -f2 | tr -d '\r')
[ -n "$REQUIRED" ] || { echo "FAIL: no 402"; exit 1; }

echo "== wisdom, paid"
SIG=$(echo "$REQUIRED" | ddev exec -s web php bin/pay.php)
c -D - -H "Mcp-Session-Id: $S" -H "PAYMENT-SIGNATURE: $SIG" "$U" -d "$CALL" | grep -iE '^HTTP|^payment-response|^\{'
