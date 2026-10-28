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
| `app/` | Symfony 8.1 + `symfony/mcp-bundle`: an MCP server with a free `echo` tool and a paid `wisdom` tool. |
| `bin/smoke.sh` | End-to-end check over real HTTP: free call, unpaid call (402), paid call (200 + receipt). |
| `bin/pay.php` | Minimal buyer: turns a `PAYMENT-REQUIRED` header into a `PAYMENT-SIGNATURE`. |

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
HTTP/1.1 402 Payment Required
Payment-Required: eyJ4NDAyVmVyc2lvbiI6Mi...
== wisdom, paid
HTTP/1.1 200 OK
Payment-Response: eyJzdWNjZXNzIjp0cnVl...
{"jsonrpc":"2.0","id":2,"result":{"content":[{"type":"text","text":"Verify the effect, not the mechanism."}],...}}
```

The MCP endpoint is `https://quebracho-x402-mcp.ddev.site/mcp` (Streamable
HTTP). You can also open it in the
[MCP Inspector](https://github.com/modelcontextprotocol/inspector).

Run the library tests (no network):

```bash
ddev exec -d /var/www/html/packages/x402 vendor/bin/phpunit tests
```

## How a paid call works

```
agent ──tools/call wisdom──▶ MCP server
      ◀── 402 + PAYMENT-REQUIRED (price, token, network, payTo)
signs an EIP-3009 transferWithAuthorization for exactly that price
      ──same call + PAYMENT-SIGNATURE──▶ X402Middleware: verify ▶ run tool ▶ settle
      ◀── 200 + tool result + PAYMENT-RESPONSE (receipt)
```

Only `tools/call` of a priced tool is charged: `initialize`, `tools/list` and
free tools pass through. The tool runs only after the payment verifies, and
settlement happens only if the tool succeeded.

## Configuration

`app/.env` holds working defaults. Override them in `app/.env.local` (git-ignored):

| Variable | Default | Meaning |
|---|---|---|
| `X402_NETWORK` | `eip155:84532` | CAIP-2 network (Base Sepolia). |
| `X402_PRICE` | `0.01` | Price per paid call, in USD. |
| `X402_PAY_TO` | `0x7e51…6510` | Who gets paid. **This is the author's wallet.** Change it to yours. |
| `X402_PAID_TOOLS` | `wisdom` | Comma-separated tool names that cost money. |

## Using the library

**Seller**: put the PSR-15 middleware in front of anything:

```php
use Quebracho\X402\{EvmExact, Networks, X402Middleware};

$price = fn ($request) => [Networks::requirements('eip155:84532', '0.01', $payTo)]; // or null = free
$middleware = new X402Middleware([new EvmExact()], $price, $psr17, $psr17);
// Optional 5th argument: a settle callback (facilitator). Without it: mock mode.
```

**Buyer**: wrap any PSR-18 client; it pays 402s within a budget:

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
`pay`, `verify`), shaped after the x402 spec rather than after EVM. `NearExact`
(`near:testnet`, NEP-366 delegate action, `ext-sodium`) is **experimental**:
its `verify()` does not tie the public key to `sender_id` (the network checks
that on settle), and it is not wired into the demo. Solana is planned.

## Status

Working and verified:
- EIP-712 / EIP-3009 signing, checked against the EIP-712 spec vectors; offline `ecrecover`.
- PSR-15 middleware: 402 flow, offline verification, settle-after-success.
- PSR-18 paying client with a per-network spending cap.
- Symfony MCP server charging a tool end to end (`bin/smoke.sh`).

Not yet:
- Settlement on testnet through a facilitator (mock mode only for now).
- The Symfony AI agent (`bin/console app:ask`) that discovers, pays and calls the tool.
- Mock mode keeps no spent-nonce store, so a payment can be replayed until it expires. A real facilitator rejects it on settle.
- Unverified: the EIP-712 domains of USDC on Ethereum Sepolia, Avalanche Fuji and Polygon Amoy (confirmed by the first testnet payment on each).

## License

MIT, see [LICENSE](LICENSE). Some library classes were first written by the
same author for the `x402_pay` Drupal module and are relicensed here; see
[packages/x402/NOTICE](packages/x402/NOTICE).
