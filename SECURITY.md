# Security policy

## Scope

This repository handles payments, so we take reports seriously, but it is a
hackathon project that runs **on testnets only**:

- No mainnet networks are configured (`packages/x402/src/Networks.php`).
- The app refuses to start unless `X402_FACILITATOR=mock`: nothing settles on chain.
- The key in `app/.env` is the public example key from the web3.js docs. It is
  unfunded on purpose; secret scanners flag it, and that is a known false positive.

No real funds are at stake. Do not send any to addresses found here.

In scope: the `quebracho/x402` library (`packages/x402`) and the demo app
(`app/`): payment verification, replay (claiming one payment twice), the
buyer's budget, address screening, and the MCP/HTTP transports.

## Reporting

Please report privately through GitHub's
[private vulnerability reporting](https://github.com/leofishman/quebracho-x402-mcp/security/advisories/new),
not in a public issue. Include what you did, what you expected and what
happened. We aim to answer within a week.
