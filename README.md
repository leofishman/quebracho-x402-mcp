# quebracho-x402-mcp

Paid MCP tools in PHP: a Symfony MCP server whose tools charge per call with
[x402](https://www.x402.org/), and a PHP library any buyer or seller can use.
An agent that pays another agent, with no API keys and no accounts. Only a
signed USDC authorization travels in an HTTP header.

Built for the Symfony AI & MCP Hackathon (team Ombu). The x402 library is
contributed by Quebracho.

> **Testnet only.** Nothing here touches mainnet funds. By default the demo
> runs in *mock mode*: signatures are real and fully verified, but nothing is
> settled on chain, so you need no wallet and no faucet.

## What's inside

| Path | What it is |
|---|---|
| `packages/x402/` | `quebracho/x402`: framework-agnostic library (PSR-7/15/17/18, `ext-gmp`). Signs and verifies x402 payments. Contains no LLM or Symfony code. |
| `app/` | Symfony 8.1 + `symfony/mcp-bundle`: an MCP server with a free `echo` tool and a paid `wisdom` tool, and `bin/console app:ask`, a Symfony AI agent that discovers, pays and calls them. |
| `bin/smoke.sh` | End-to-end check over real HTTP: free call, unpaid call (PaymentRequired), paid call (result + receipt). |
| `bin/pay.php` | Minimal buyer: turns a PaymentRequired tool result into the same call, paid. |

## Quick start (5 minutes)

Requirements: [DDEV](https://ddev.com/) (PHP runs inside it, nothing to
install on the host) and `curl`.

```bash
git clone https://github.com/leofishman/quebracho-x402-mcp.git
cd quebracho-x402-mcp
ddev start
ddev composer install -d packages/x402
ddev composer install -d app
bin/smoke.sh
```

Expected output, abridged:

```
== echo (free)
{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"hola hackathon"}],...}}
== wisdom, unpaid
{"jsonrpc":"2.0","id":2,"result":{"isError":true,"structuredContent":{"x402Version":2,...
== wisdom, paid
{"jsonrpc":"2.0","id":2,"result":{"content":[...],"_meta":{"x402/payment-response":{"success":true,...}}}}
OK
```

The MCP endpoint is `https://quebracho-x402-mcp.ddev.site/mcp` (Streamable
HTTP). You can also open it in the
[MCP Inspector](https://github.com/modelcontextprotocol/inspector).

Run the library tests (no network):

```bash
ddev exec -d /var/www/html/packages/x402 vendor/bin/phpunit tests
```

## The agent: `app:ask`

A Symfony AI agent (`symfony/ai-bundle`) connects to the MCP server as a
client (`mcp.clients.buyer.self` in `app/config/packages/mcp.yaml`), lists its
tools and offers them to the LLM. The PSR-18 client under that MCP connection
is `Quebracho\X402\PayingMcpClient`: when a tool answers with a
PaymentRequired it signs with `X402_BUYER_KEY`, stays within
`X402_BUDGET_USD`, resends the same call with the payment in `_meta` and
reads the receipt.

Point it at any OpenAI-compatible server with tool calling (llama.cpp,
Ollama, ...) in `app/.env.local`:

```bash
LLM_BASE_URL=http://host:8080   # without /v1
LLM_MODEL=your-model
LLM_API_KEY=...
```

```bash
ddev exec -d /var/www/html/app bin/console app:ask "dame un consejo de ingeniería pago"
```

```
 Tools on the server: echo, wisdom
 Paid tool "wisdom": 0.01 USDC (10000 atomic) on eip155:84532 to 0x7e51…6510, settled
 Receipt: {"success":true,"transaction":"","network":"eip155:84532","payer":"0x2c75…5c23"}

Answer
------
Aquí tienes un consejo de ingeniería pago: "Verifica el efecto, no el mecanismo." ...
```

No LLM at hand? `--tool wisdom` calls the tool directly through the same
paying client. In mock mode `transaction` is empty: nothing moves on chain.

## How a paid call works

x402 travels inside MCP messages, as the official
[MCP transport spec](https://github.com/x402-foundation/x402/blob/main/specs/transports-v2/mcp.md)
defines, so any x402-aware MCP client can pay:

```
agent ──tools/call wisdom──▶ MCP server
      ◀── result: isError + PaymentRequired (price, token, network, payTo)
signs an EIP-3009 transferWithAuthorization for exactly that price
      ──same call + params._meta["x402/payment"]──▶ X402McpMiddleware: verify ▶ run tool ▶ settle
      ◀── tool result + _meta["x402/payment-response"] (receipt)
```

Only `tools/call` of a priced tool is charged: `initialize`, `tools/list` and
free tools pass through. The tool runs only after the payment verifies, and
each payment is claimed once before the tool runs (concurrent replays get `duplicate_settlement`), settlement happens only if the tool succeeded; if settlement fails, the tool
output is withheld. Paid tools cannot be called inside a JSON-RPC batch.

### Address screening

Both sides screen addresses with `App\X402\Screening`: the server checks the
payer once its signature is verified, the agent checks `payTo` before it signs.
First match wins: **our allowlist** (passes, even over OFAC), **our denylist**,
**OFAC's sanctioned addresses** (a local copy), and optionally **Chainalysis'
sanctions API** (`X402_SCREENING_API_KEY`). The API lookup starts when the payment is
checked and is read just before settling; answers are cached
`X402_SCREENING_CACHE_TTL` seconds, and no answer in time follows
`X402_SCREENING_UNAVAILABLE`. Until `app:screening sync` has run once, the OFAC step
matches nothing and only logs a warning: run it before taking payments.

```bash
ddev exec --dir /var/www/html/app bin/console app:screening sync          # download OFAC's lists
ddev exec --dir /var/www/html/app bin/console app:screening deny 0x…      # our own report
ddev exec --dir /var/www/html/app bin/console app:screening allow 0x…     # override a public list
ddev exec --dir /var/www/html/app bin/console app:screening check 0x…
```

## Configuration

`app/.env` holds working defaults. Override them in `app/.env.local` (git-ignored):

| Variable | Default | Meaning |
|---|---|---|
| `X402_NETWORK` | `eip155:84532` | CAIP-2 network (Base Sepolia). |
| `X402_PRICE` | `0.01` | Price per paid call, in USD. |
| `X402_PAY_TO` | `0x7e51…6510` | Who gets paid. **This is the author's wallet.** Change it to yours. |
| `X402_PAID_TOOLS` | `wisdom` | Comma-separated tool names that cost money. |
| `X402_FACILITATOR` | `mock` | Must be set explicitly. Only `mock` exists yet (verified signatures, nothing settled); anything else fails closed: every `/mcp` request errors, nothing is served for free. |
| `X402_BUYER_KEY` | web3.js docs key | The agent's buyer key: public and unfunded, enough for mock mode. Testnet keys only. |
| `X402_BUDGET_USD` | `0.05` | The agent's spending cap per run on `X402_NETWORK`. |
| `X402_SCREENING_API_KEY` | empty | Chainalysis sanctions API key. Empty: only our lists and OFAC's. |
| `X402_SCREENING_CACHE_TTL` | `86400` | Seconds an API answer (clean or sanctioned) is kept. |
| `X402_SCREENING_TIMEOUT` | `3` | Seconds to wait for the API. |
| `X402_SCREENING_UNAVAILABLE` | `reject` | No API answer in time (or a 5xx): `reject` or `allow`. |
| `MCP_URL` | the DDEV site's `/mcp` | The MCP server the agent connects to. |
| `LLM_BASE_URL`, `LLM_MODEL`, `LLM_API_KEY` | placeholders | The agent's OpenAI-compatible LLM; set them in `.env.local`. |

## Using the library

**Seller (MCP)**: `X402McpMiddleware` charges tool calls in band; `$price`
gets the `tools/call` params. **Seller (plain HTTP)**: `X402Middleware` answers
402 with `PAYMENT-REQUIRED`, for any endpoint:

```php
use Quebracho\X402\{EvmExact, FileClaims, Networks, X402Middleware};

$price = fn ($request) => [Networks::requirements('eip155:84532', '0.01', $payTo)]; // or null = free
$middleware = new X402Middleware([new EvmExact()], $price, $claim, $psr17, $psr17);
// Optional 5th argument: a settle callback (facilitator). Without it: mock mode.
```

**Buyer (MCP)**: `PayingMcpClient` wraps the PSR-18 client of an MCP HTTP
transport and pays tools in band; same arguments as below plus a PSR-17 stream
factory. **Buyer (plain HTTP)**: wrap any PSR-18 client; it pays 402s within a budget:

```php
use Quebracho\X402\{Budget, EvmExact, PayingHttpClient, Signer};

$client = new PayingHttpClient(
    $anyPsr18Client,
    [new EvmExact(new Signer($testnetKey))],
    Budget::usd('eip155:84532', '0.05'),   // never spends more than 5 cents
);
```

**Networks**: built in: Base Sepolia, Arc testnet, Ethereum Sepolia, Avalanche
Fuji, Polygon Amoy. Any other EVM chain whose token implements EIP-3009:

```php
$network = Networks::add(chainId: 4202, asset: '0x…', name: 'USDC', version: '2');
```

**Other chain families** implement the `Scheme` interface (`supports`,
`pay`, `verify`, `payer`), shaped after the x402 spec rather than after EVM. `NearExact`
(`near:testnet`, NEP-366 delegate action, `ext-sodium`) is **experimental**:
its `verify()` does not tie the public key to `sender_id` (the network checks
that on settle), and it is not wired into the demo. Solana is planned.

## Attacks it resists

Each line is a test that fails if the defence breaks (`packages/x402/tests`,
`app/tests`). Most come from reviewing this code against the x402 v2 spec;
the reasons are in [DECISIONS.md](DECISIONS.md).

**Seller side**
- **One signature, many deliveries.** Each payment is claimed once (network,
  payer, nonce) before the tool runs; copies get `duplicate_settlement` (D5;
  20 concurrent copies were checked by hand, one delivery).
  `X402McpMiddlewareTest::testAPaymentBuysOneDeliveryEvenUnsettled`. The EIP-712 encoder reads `0xAB…`, `0xab…` and `ab…` as the same nonce,
  so a nonce spelled differently would be the same signature with a new claim
  key: verification accepts one spelling only, and the key ignores hex case (D9).
  `EvmExactTest::testOneSignatureIsOneClaimHoweverTheNonceIsSpelled`.
- **Malleated signatures**: high-s rejected (EIP-2). `EvmExactTest::testRejectsAMalleatedHighSSignature`.
- **Malformed payloads** get a rejection, never a 5xx. `EvmExactTest` (`tampered`).
- **Paid output without payment**: settlement only after the tool succeeded, and
  a failed or odd facilitator answer withholds the output.
  `X402McpMiddlewareTest::testABrokenFacilitatorWithholdsTheOutput`.
- **Long-lived signatures**: `validBefore` capped by the offer's timeout. `EvmExactTest::testRejectsAValidityWindowLongerThanTheOffer`.
- **Paid calls inside a JSON-RPC batch**: refused (D3). `X402McpMiddlewareTest::testBatchWithAPaidCallIsRejected`.

**Buyer side**
- **A hostile 402**: the agent signs only known tokens and EIP-712 domains,
  positive integer amounts within its cap, a canonical `payTo` (`0x` + 40 hex;
  a padded one would sign to an address screening never saw), and short
  validity. `BudgetTest::testRefusesAHostileOffer`.
- **A seller that never confirms** cannot drain past the cap: the budget is spent
  at signing, and given back only when the chain says the nonce was never used,
  60 s after expiry (D6, D8). `PayingMcpClientTest`, `BudgetTest`.

**Both sides**
- **Sanctioned or reported addresses**: payer and `payTo` screened (D7); a list
  or API that cannot answer fails closed. `app/tests/X402/ScreeningTest`.

## Status

Working and verified:
- EIP-712 / EIP-3009 signing, checked against the EIP-712 spec vectors; offline `ecrecover`.
- PSR-15 middlewares: x402 over MCP (in band) and over plain HTTP (402); offline verification, settle-after-success.
- Verification aligned with the reference facilitator: spec §9 reason codes, low-s signatures only, 6 s expiry slack.
- PSR-18 paying clients with a per-network spending cap: MCP in band (`PayingMcpClient`) and plain HTTP 402 (`PayingHttpClient`).
- Symfony MCP server charging a tool end to end (`bin/smoke.sh`).
- Symfony AI agent (`app:ask`) that discovers, pays and calls the tool, verified against a local llama.cpp model (mock mode).
- Address screening on both sides (PSR-14 `AddressCheck`): our allow/deny lists, OFAC's sanctioned addresses, optional Chainalysis API.

Not yet:
- Settlement on testnet through a facilitator (mock mode only for now).
- Unverified: the EIP-712 domains of USDC on Ethereum Sepolia, Avalanche Fuji and Polygon Amoy (confirmed by the first testnet payment on each).

Plan, open tasks and what comes after the hackathon: [ROADMAP.md](ROADMAP.md).

## License

MIT, see [LICENSE](LICENSE). Some library classes were first written by the
same author for the `x402_pay` Drupal module and are relicensed here; see
[packages/x402/NOTICE](packages/x402/NOTICE).
