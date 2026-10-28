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

## D8 — Deferred screening, budget freed by the chain, NEAR payer proven or absent

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: Leo accepted three follow-ups to D7. First, a sanctions API must not add its full latency to every payment. Second, a buyer that signed for a seller who then refused it should get that budget back. Third, a NEAR `sender_id` cannot be trusted offline.
- Decision:
  - A listener may `defer()` a closure. The seller dispatches the check right after verify and reads the deferred answer just before settling; a late rejection withholds the output, the same as a failed settle. The buyer waits before signing.
  - In the app, the Chainalysis request leaves when the check is dispatched. Answers, clean or sanctioned, are cached `X402_SCREENING_CACHE_TTL` seconds. With no answer within `X402_SCREENING_TIMEOUT` (or a 5xx), `X402_SCREENING_UNAVAILABLE` decides: `reject` (the default) or `allow`. Any other error rejects. An address on our allowlist never reaches the API.
  - `Budget` keeps every signed payment whose receipt did not say success. When the buyer next pays, `reclaim()` frees an entry once its `validBefore` has passed and the token's `authorizationState(from, nonce)` on chain says the nonce is unused. A used nonce or an RPC error keeps it spent. Never on the seller's word (D6).
  - `NearExact::payer()` returns the `sender_id` only when an injected `$keyOwner` (RPC `view_access_key`) proves the key is theirs. Otherwise it returns NULL, which screening rejects.
- Consequences:
  - A payer rejected late still had the tool run (its output is withheld) and its payment claimed.
  - The overlap between lookup and tool was not measured. Symfony HttpClient moves a request forward when the code waits on a response, so with a fast tool the API sets the pace on a payer's first payment, and the cache answers after that.
  - Budget release is EVM only and per process; it needs the network's public RPC (from `Networks`).
  - Release waits 60 s past `validBefore`, since the chain's clock, not ours, decides whether a signature can still settle. The RPC is trusted: one that lies or lags can free budget it should not.
  - An unread lookup (the tool failed, the claim was a duplicate) is cancelled, not read, so it neither blocks nor throws. Replays of one signature still each start a lookup before the claim (ponytail: no in-flight de-duplication).
  - NEAR screening needs that RPC check; NEAR is still out of the demo (D4).

## D9 — One spelling per signed value, and the spec gaps we know of

- Date: 2026-10-28
- Status: accepted (Leo)
- Context: x402-reviewer, second pass against the v2 spec. The EIP-712 encoder reads `0xAB…`, `0xab…` and `ab…` as the same bytes, while `Settlement::key` hashed the nonce as sent. One signature, re-sent with its nonce spelled differently, verified every time and won a new claim: N deliveries for one payment, the hole D5 says is closed (critical, reproduced). Malformed numbers (`validAfter: "0abc"`) also reached `gmp_init` and threw: a 5xx instead of a rejection.
- Decision: `EvmExact::verify` accepts one spelling only: `from`/`to` `0x`+40 hex, `nonce` `0x`+64 hex, signature `0x`+130 hex, amounts and times plain decimals with no leading zero. Anything else is `invalid_payload`. `Settlement::key` lowercases the nonce too, so hex case alone cannot make a second claim. The HTTP transport now sends the failed `SettlementResponse` in `PAYMENT-RESPONSE`, as `transports-v2/http.md` asks.
- Known divergences, left on purpose until a real facilitator exists:
  - `settlement_pending` is treated as a failure (output withheld) but now keeps its transaction hash in the receipt, so the seller can reconcile it. Nothing retries or reconciles it yet.
  - The HTTP transport answers an invalid payment with 402, not the 400 the spec's error table gives (MCP has no status codes; only `X402Middleware` is affected).
  - `duplicate_settlement` comes from the SVM scheme, not v2 §9; official clients do not know it.
  - EVM verification is offline: no balance check and no `transferWithAuthorization` simulation (`scheme_exact_evm.md`, phase 2). In mock mode a valid signature from an empty wallet gets the delivery.
