# DECISIONS — quebracho-x402-mcp

One decision per section, numbered in order. The agent proposes, Leo decides.
An accepted decision is never rewritten: if it changes, a new one replaces it.

## D1 — The x402-foundation specs are the norm

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: the canonical x402 repo moved from `coinbase/x402` to `github.com/x402-foundation/x402` (`specs/` plus a TypeScript reference implementation; Go mirrors its layout). Our library had its own error names and formats.
- Decision: where the spec defines something (payload format, §9 error codes, verification rules), we follow the spec. Where it is silent, we follow the TypeScript facilitator.
- Consequences: `EvmExact` returns §9 codes, requires `x402Version` 2 and `extra.name/version`, and allows 6 s of slack on `validBefore`; `Signer::recover` rejects high-s signatures. Official clients understand our rejections.

## D2 — MCP charges in band (`_meta`), not with HTTP 402

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: `specs/transports-v2/mcp.md` defines x402 over MCP: PaymentRequired as an `isError` tool result, the payment in `params._meta["x402/payment"]`, the receipt in `result._meta["x402/payment-response"]`. An official MCP client does not understand an HTTP 402.
- Decision: the MCP server uses `X402McpMiddleware`. `X402Middleware` (HTTP 402) stays in the library for plain web endpoints.
- Consequences: the MCP buyer (the `app:ask` agent) cannot use `PayingHttpClient` as is: it must read `structuredContent` and resend with `_meta`. If settlement fails, the tool output is withheld.

## D3 — Paid tools are not allowed in JSON-RPC batches

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: in a batch, one payment covered several calls and was charged even if some failed. MCP 2025-06-18 removed batching.
- Decision: a batch containing a paid tool call is rejected with JSON-RPC `-32600`.
- Consequences: each call is charged on its own; `Pricing` no longer multiplies the price.

## D4 — NEAR stays experimental, offline, and out of the demo

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: PR #1 (Jules) brought `NearExact`; the official spec `scheme_exact_near.md` also requires RPC checks (`view_access_key`, nonce, FullAccess, balances).
- Decision: `NearExact` follows the spec on payload shape (`{"signedDelegateAction"}`), asset, block window, nonce and gas; ed25519 only (secp256k1 is rejected). The RPC checks belong to the facilitator, not to the offline `verify()`. It is not wired into the app.
- Consequences: a NEAR payment that passes `verify()` can still fail at settlement (foreign key, used nonce). It enters the demo only by a new decision.

## D5 — A payment is claimed once, before the tool runs

- Date: 2026-10-28
- Status: accepted (Leo, via review)
- Context: the x402-reviewer sent one payment 20 times in parallel and got 20 deliveries; the tool runs before settlement, so even a real facilitator (which rejects the spent nonce) would deliver N times and charge once. Mock mode was also the silent default.
- Decision: both middlewares take a required `$claim` (atomic, durable `fn(string $key): bool`) and call it after verify, before the handler. The key is network + payer + nonce for EVM, so re-signing a nonce does not buy a second delivery. `EvmExact` caps `validBefore` at the offer's `maxTimeoutSeconds` (+60 s). The app uses `FileClaims` and requires `X402_FACILITATOR=mock` explicitly.
- Not done, on purpose: binding a payment to a tool name or its arguments. `resource` is not signed, so checking it is cosmetic; with single-use claims a payment buys exactly one call at its exact price.
- Consequences: a payment whose tool then fails is consumed without being charged; the buyer signs a new one. `FileClaims` is per host and never pruned (bounded by the `validBefore` cap).

## D6 — The buyer spends its budget when it signs, and signs only known tokens

- Date: 2026-10-28
- Status: accepted (Leo, via review)
- Context: the x402-reviewer showed the buyer's cap counted only payments the seller confirmed; a seller that never confirms got 20 signed authorizations against a 5-call cap, each settleable on its own. It also got signatures for arbitrary tokens and a 10-year validity.
- Decision: `Budget::allows` is the one gate for both buyers: known network and token (asset and EIP-712 domain from `Networks`), positive integer amount, `maxTimeoutSeconds` ≤ 600, within the cap. `spend()` runs before signing and is never refunded by what the seller says.
- Not done, on purpose: a `payTo` allowlist (the cap bounds the loss; add when the agent pays third parties), a durable budget (per run for now), human confirmation per payment.
- Consequences: a refused or unconfirmed payment still uses budget until its `validBefore` passes; the cap is a hard ceiling on what can be signed in one run.

## D7 — Addresses are screened through a PSR-14 event, on both sides

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: Leo wants to block known bad wallets (sanctioned or reported) to protect sellers and, above all, paying agents. The official x402 SDKs offer no screening of their own: they expose hooks around verify/settle, and the CDP facilitator screens OFAC/KYT at settlement.
- Decision: the library dispatches an `AddressCheck` (PSR-14, stoppable) when given a dispatcher: on the payer after `verify()` (signature proven) and before the claim; on `payTo` before the buyer signs. `Scheme` gains `payer()`. It fails closed: an unreadable address or a throwing listener is a rejection. The app answers it with `App\X402\Screening`: our allowlist > our denylist > OFAC (local file, `app:screening sync`) > Chainalysis API (opt-in by key). No Symfony detection: PSR-14 is the seam, Symfony's dispatcher is one implementation.
- Not done, on purpose: IP blocking (infrastructure's job), async screening (no event loop; the API is opt-in for whoever puts compliance before latency), an admin UI (a console command for now), rejection codes from the spec (§9 has none for this; ours are `address_denied`, `address_sanctioned`, `address_check_failed`).
- Consequences: a payer blocked by the seller has still spent the buyer's budget (it signed). NEAR's payer is `sender_id`, not bound to the key offline; a forged one passes screening and fails at settlement. OFAC lists are mainnet addresses, applied to every EVM chain alike (EVM entries only: 124 of 210). `Budget::allows` signs only a canonical `payTo` (`0x` + 40 hex): Eip712 pads, so `0330…` or `0x000330…` would sign to a sanctioned address that screening saw in another form (x402-reviewer, critical). A corrupt or unreadable list stops payments; a never-synced OFAC list only logs a warning (ponytail: no staleness check yet).
