<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Http\Message\ServerRequestInterface;
use Quebracho\X402\Networks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What an MCP request costs: tools/call of a paid tool, nothing else.
 */
final class Pricing
{
    /**
     * @param list<string> $paidTools
     */
    public function __construct(
        #[Autowire(env: 'csv:X402_PAID_TOOLS')] private readonly array $paidTools,
        #[Autowire(env: 'X402_NETWORK')] private readonly string $network,
        #[Autowire(env: 'X402_PRICE')] private readonly string $price,
        #[Autowire(env: 'X402_PAY_TO')] private readonly string $payTo,
    ) {
    }

    /**
     * @return list<array>|null The x402 `accepts` list, or null when free.
     */
    public function __invoke(ServerRequestInterface $request): ?array
    {
        $body = $request->getBody();
        $message = json_decode((string) $body, true);
        // The transport reads the body after us.
        if ($body->isSeekable()) {
            $body->rewind();
        }
        if (!\is_array($message)) {
            return null;
        }
        // A JSON-RPC batch pays for every paid call in it.
        // ponytail: charged even if one call fails (HTTP 200 + JSON-RPC error);
        // per-call settle or rejecting paid batches if that matters.
        $calls = array_is_list($message) ? $message : [$message];
        $paid = \count(array_filter($calls, fn ($m) => \is_array($m)
            && 'tools/call' === ($m['method'] ?? null)
            && \in_array($m['params']['name'] ?? null, $this->paidTools, true)));
        if (0 === $paid) {
            return null;
        }
        $req = Networks::requirements($this->network, $this->price, $this->payTo);
        $req['amount'] = gmp_strval(gmp_mul($req['amount'], $paid));

        return [$req];
    }
}
