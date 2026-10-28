# Roadmap and tasks

Built for the Symfony AI & MCP Hackathon (deadline 2026-11-22; our own deadline is 11-20).
Decisions are recorded in [DECISIONS.md](DECISIONS.md).

## Plan

| Dates | Step | Status |
|---|---|---|
| 10-28 → 11-01 | `packages/x402`: signing, verification, tests | done |
| 11-02 → 11-03 | 402 middleware, MCP in band (D2), claim once (D5) | done |
| 11-04 → 11-05 | Paying clients, budget (D6), `app:ask` agent | done |
| — | Address screening: OFAC, own lists, Chainalysis (D7) | done |
| — | Deferred screening, budget released by the chain, NEAR payer proven (D8) | done |
| 11-06 → 11-08 | Settle on testnet through a real facilitator behind `App\X402\Facilitator` | next |
| **11-09** | **Cutoff**: if the full flow does not work by then, stay in mock mode and move on to the README and video | |
| 11-10 → 11-15 | Durable budget, `demo.sh`, error handling, README | |
| 11-16 → 11-19 | 90 s video, minimal CI | |
| 11-20 | Submit | |

## Tasks (hackathon and app)

Tasks for the library are in [packages/x402/ROADMAP.md](packages/x402/ROADMAP.md).

- [ ] Settle on testnet (Base Sepolia) through a facilitator.
- [ ] Screening in the app: warn when the OFAC list is stale (mtime older than N days).
- [ ] Try the server with MCP Inspector.
- [ ] Before submitting: review the default `X402_PAY_TO` (the author's wallet).

## After the hackathon

- Split `packages/x402` into its own repo (`git subtree split`, history kept) and publish it on Packagist.
- phay.io: a hosted registry of trusted sellers and reported addresses, behind the `AddressCheck` hook.
- The Drupal module `x402_pay` uses this library.
