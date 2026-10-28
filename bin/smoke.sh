#!/usr/bin/env bash
# MCP handshake + echo tool call against the DDEV site.
set -euo pipefail
U=${MCP_URL:-https://quebracho-x402-mcp.ddev.site/mcp}
c(){ curl -sSk --http1.1 -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' "$@"; }
S=$(c -D - -o /dev/null "$U" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}' | grep -i '^mcp-session-id' | cut -d' ' -f2 | tr -d '\r')
c -H "Mcp-Session-Id: $S" "$U" -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' -o /dev/null
c -H "Mcp-Session-Id: $S" "$U" -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"text":"hola hackathon"}}}'
echo
