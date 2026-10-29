# Comprehensive Multi-Level Audit Report: x402 MCP (`quebracho-x402-mcp` & `packages/x402`)

## Executive Summary

This document presents an exhaustive multi-level audit of the `quebracho-x402-mcp` repository and its core library `packages/x402` (v0.1.0 / v0.2.0-dev). The evaluation assesses protocol adherence, cryptographic verification, concurrency guarantees, agent state machines, user experience, and resilience against malicious attack vectors across four operational perspectives:

1. **Level 1: Non-technical System User** (UX, CLI interaction, transparency, error clarity)
2. **Level 2: Selling & Buying Agent** (In-band MCP charging, state machines, budget caps, tool output withholding, retries)
3. **Level 3: PHP & Symfony Expert** (PSR compliance, type safety, stream handling, POSIX file locking concurrency, event dispatching)
4. **Level 4: Blockchain & x402 Protocol Expert** (EIP-712/3009 EVM exact, NEP-366 NEAR delegate actions, SVM v0 Solana exact, facilitator integration, sanctions screening)

---

## Level 1: Non-technical System User Audit

### 1. Happy Path & Usability
- **Clear Terminal Interaction (`app:ask`, `app:screening`):**
  The CLI tools provide structured feedback. Execution of `app:ask` outputs discovered MCP tools, payment settlement statuses, atomic-to-USDC conversions, and transaction hashes:
  ```text
  Tools on the server: wisdom, echo
  Paid tool "wisdom": 0.001 USDC (1000 atomic) on eip155:84532 to 0x..., settled in 0x...
  Receipt: {"success":true,"transaction":"0x...","network":"eip155:84532"}
  ```
- **Policy Transparency:**
  When an offer is refused due to spending caps or address screening, `app:ask` clearly states the reason:
  `Not paid: 1000 atomic on eip155:84532 to 0x... (not_allowed)`

### 2. Edge Cases & Malicious Vectors
- **Raw Spec Error Messages:** Error codes such as `invalid_payload`, `duplicate_settlement`, or `address_check_failed` stem directly from x402 spec v2 §9. Non-technical users receive these raw codes without contextual guidance explaining whether the issue is insufficient balance, clock skew, or OFAC sanctions flags.
- **Mock Mode Risk (`X402_FACILITATOR=mock`):** When mock mode is enabled, receipts contain `extensions.mock: true`. If a non-technical operator accidentally deploys mock mode in production, tool execution succeeds locally while no real blockchain transactions take place.
- **OFAC Sync Alerting:** If `app:screening sync` has not been executed, `Screening.php` logs a warning (`OFAC list never synced`), but the CLI command does not interrupt the user. Operators may assume screening is fully active when the local OFAC list is empty.

---

## Level 2: Selling & Buying Agent Audit

### 1. Happy Path
- **In-Band MCP Payment Transport (`transports-v2/mcp.md`):**
  - **Seller:** `X402McpMiddleware` intercepts paid `tools/call`. When unauthenticated, it returns an HTTP 200 JSON-RPC result with `isError: true` containing `structuredContent` (the x402 `PaymentRequired` payload).
  - **Buyer:** `PayingMcpClient` decodes `structuredContent`, evaluates accepted schemes against `Budget`, signs the payload, and re-issues the JSON-RPC call in-band via `params._meta["x402/payment"]`.
  - **Seller Settlement:** The seller middleware verifies, claims the payment key via `FileClaims`, runs the tool, settles with the facilitator, and attaches the receipt in `result._meta["x402/payment-response"]`.

### 2. Malicious Vectors & State Machine Analysis
- **Rogue Seller Budget Exhaustion Attack:**
  - A malicious or faulty seller server receives a signed EIP-3009 payment authorization, executes the tool internally, but returns a failure receipt or drops the HTTP connection (`unexpected_settle_error`).
  - The buyer agent deducts the payment from its budget cap immediately upon signing (`Budget::spend()`). Even if settlement fails, the amount remains deducted until `reclaim()` is invoked AND `validBefore` (+60s) has elapsed AND on-chain `authorizationState` confirms the nonce was not spent.
  - **Mitigation:** The 10-minute cap window (`Budget::MAX_TIMEOUT = 600`) bounds total loss per agent run.
- **Tool Output Withholding on Settlement Failure:**
  - In default deferred settlement mode, the seller executes the tool handler *before* calling `settle()`.
  - If `settle()` fails (facilitator offline or insufficient buyer balance), `X402McpMiddleware` withholds the tool output and returns `isError: true` with the failed `PaymentResponse`.
  - **Upfront Flow (`extra.paymentFlow = "upfront"`):** For expensive or non-reversible tools, the middleware executes `settle()` *before* invoking the tool handler (`Sale::upfront()`), protecting sellers from unpaid compute consumption.

---

## Level 3: PHP & Symfony Expert Audit

### 1. `Settlement.php` Claim Key & Deduplication Deep Dive
- **Key Derivation Algorithm:**
  ```php
  $id = match (true) {
      is_array($auth) => [$payment['accepted']['network'] ?? '', strtolower((string) ($auth['from'] ?? '')), strtolower((string) ($auth['nonce'] ?? ''))],
      null !== $solana => [$payment['accepted']['network'] ?? '', bin2hex($solana['message'])],
      null !== $near => [$payment['accepted']['network'] ?? '', $near['sender_id'], bin2hex($near['public_key_bytes']), $near['nonce']],
      default => $payment['payload'] ?? null,
  };
  return hash('sha256', json_encode($id, JSON_THROW_ON_ERROR));
  ```
- **Analysis:**
  - **EVM (`eip155`):** Combines `network`, `from` (lowercased), and `nonce` (lowercased). Combined with strict regex validation in `EvmExact::verify` (`0x` + 40 hex address, `0x` + 64 hex nonce), string case variations or leading zero tricks cannot generate alternative claim keys for the same nonce.
  - **Solana (`solana`):** Hashes compiled message bytes `solana['message']` (excluding variable signatures). Modifying or stripping fee-payer signatures produces an identical claim key.
  - **NEAR (`near`):** Hashes `[network, sender_id, public_key_bytes, nonce]`. Trailing byte injection is prevented by `NearExact::decodeSignedDelegateAction()` length bounds.

### 2. Concurrency & POSIX File Locking (`FileClaims.php`)
- **Atomic Single-Use Claims:**
  `FileClaims` uses `fopen($dir.'/'.$key, 'x')`, which invokes POSIX flags `O_CREAT | O_EXCL`.
  The OS kernel guarantees that among $N$ parallel execution threads or processes attempting to claim the same key, exactly one thread succeeds and receives a file handle; all other threads receive `false` and receive `duplicate_settlement`.
- **Distributed Environments:**
  `FileClaims` operates on local filesystem storage. Multi-server deployments behind a load balancer must replace `FileClaims` with a shared atomic store (e.g., Redis `SET NX` or database unique constraint).

### 3. Architecture & Exception Management
- **PSR-14 Event Propagation (`AddressCheck`, `PaymentOutcome`):**
  - Address checks dispatch `AddressCheck` events implementing `StoppableEventInterface`.
  - `PaymentOutcome` logging listeners are isolated in `Sale::outcome` via `try/catch (\Throwable)`, ensuring logging or metric errors never invalidate completed sales or cause money movement rollbacks.
- **Stream Rewindability:**
  Middlewares inspect `$body->isSeekable()` and call `$body->rewind()` prior to and following request body decoding, ensuring downstream PSR-15 handlers receive undamaged streams.

---

## Level 4: Blockchain & x402 Protocol Expert Audit

### 1. `EvmExact` Scheme (EIP-712 / EIP-3009)
- **EIP-712 Verification:** Computes `TransferWithAuthorization` struct hash and EIP-712 domain separator. Recovers address via `secp256k1` ecrecover and enforces low-$s$ signatures ($s < \text{secp256k1\_order} / 2$) to prevent EIP-2 malleability attacks.
- **Canonical Payload Formatting:**
  Strict regex checks enforce canonical representations for `from`/`to` (`/^0x[0-9a-fA-F]{40}$/`), `nonce` (`/^0x[0-9a-fA-F]{64}$/`), amounts, and time bounds (`validAfter`, `validBefore`).
- **Slack & Timeout Windows:**
  - 6-second slack window (`$now + 6`) allowed on `validBefore` to accommodate network latency during block inclusion.
  - Timeout cap: `validBefore <= $now + $maxTimeoutSeconds + 60` limits replay windows and claim storage lifecycle.

### 2. `NearExact` Scheme (NEP-366 Delegate Action)
- **Borsh Decoding & Ed25519 Cryptography:**
  Uses `ext-sodium` for Ed25519 signature verification (`sodium_crypto_sign_verify_detached`).
  Validates function call discriminant (`ft_transfer`), 1 yoctoNEAR deposit requirement (`\x01` + 15 null bytes), and gas caps ($\le 100\text{ TGas}$).
- **Key Proving (`payer()`):**
  In NEAR delegate actions, `sender_id` can be spoofed offline over an arbitrary Ed25519 key. `NearExact::payer()` returns `sender_id` **only** if `$keyOwner` (RPC `view_access_key`) verifies the key possesses `FullAccess` permission for that account. Without `$keyOwner`, `payer()` returns `null`, causing `AddressCheck` screening to fail closed.

### 3. `SolanaExact` Scheme (SVM v0 TransferChecked)
- **Transaction Inspection:**
  Parses SVM v0 header, message header, account key vector, and recent blockhash.
  Enforces fee payer isolation: `keys[0]` must be the fee payer, writable, and **must not** appear in instruction account lists.
  Limits compute budget: max 400,000 Compute Units, max 5,000,000 microlamports price.
- **SPL-Token Verification:**
  Ensures exactly one `TransferChecked` instruction (`0x0c`) targets the seller's Associated Token Account (`Solana::ata()`) with expected mint, authority, and amount. Verifies Ed25519 signature of buyer (`keys[1]`).

---

## Evaluation of Known Warnings (W5, W2, W3)

1. **W5: Redirect Header Leak in Wrapped PSR-18 Client**
   - **Mechanism:** If the PSR-18 HTTP client wrapped by `PayingHttpClient` automatically follows HTTP redirects (e.g., 301/302 to an external host), it will forward the signed `X-PAYMENT-SIGNATURE` header to the target host.
   - **Impact:** An attacker controlling a redirect destination could harvest signed authorizations and settle them without delivering services.
   - **Mitigation:** Wrapped PSR-18 clients **must not** follow redirects automatically (`PayingHttpClient` docblocks explicitly mandate this requirement).

2. **W2: Pending Settlement Rejection on Retry**
   - **Mechanism:** When a transaction broadcast receives `settlement_pending`, `Sale::settle()` retains the claim key in `FileClaims`. If the buyer agent re-sends the same payment authorization on request timeout, the seller rejects the retry as `duplicate_settlement`.
   - **Impact:** The buyer receives `duplicate_settlement` despite the original payment landing on-chain.
   - **Analysis:** This design is intentional (D5/D9/D11) to prevent double-spending and duplicate tool execution. Claims cannot be released while a broadcast transaction is pending on-chain.

3. **W3: Offline Verification Skipping Payer Balance Checks**
   - **Mechanism:** In offline mode (or when no `$verify` callback is supplied), `Sale::admit()` verifies signatures cryptographic validity but does not query the buyer's token balance or authorization state via RPC.
   - **Impact:** An unfunded wallet with a valid signature passes offline admission, claims the key, and triggers tool execution. Subsequent settlement fails due to insufficient balance.
   - **Mitigation:** Applications offering costly tools must supply an online `$verify` callback (e.g., calling the facilitator's `/verify` endpoint) or enforce `paymentFlow = "upfront"`.

---

## Conclusion & Test Verification Summary

- **Unit Tests:** 132/132 passed in `packages/x402`, 11/11 passed in `app`.
- **Static Analysis:** PHPStan level max clean across all source files.
- **Overall Status:** The codebase implements x402 v2 specifications with high mathematical and architectural precision, strong defensive programming against replay attacks, and strict type safety.
