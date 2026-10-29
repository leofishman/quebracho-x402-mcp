<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Log\LoggerInterface;
use Quebracho\X402\Settlement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Settles paid calls. X402_FACILITATOR is "mock" (signature checked, nothing
 * moves) or a facilitator's base URL, e.g. https://x402.org/facilitator.
 * Anything else makes every /mcp request fail (the controller cannot be
 * built) instead of serving paid tools for free.
 */
final class Facilitator
{
    public function __construct(
        #[Autowire(env: 'X402_FACILITATOR')] private readonly string $mode,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $http,
    ) {
        if ('mock' !== $mode && !preg_match('#^https://#', $mode)) {
            throw new \LogicException(\sprintf('X402_FACILITATOR="%s": "mock" or an https:// facilitator URL.', $mode));
        }
    }

    public function __invoke(array $payment, array $req): array
    {
        if ('mock' === $this->mode) {
            $this->logger->warning('x402 mock settlement: signature verified, nothing moved on chain.', ['network' => $req['network'], 'amount' => $req['amount']]);

            return Settlement::run(null, $payment, $req);
        }

        // Settlement::run reads the answer without trusting it; a thrown error is a failed settle.
        return Settlement::run(fn (): array => $this->http->request('POST', rtrim($this->mode, '/').'/settle', [
            'json' => ['x402Version' => 2, 'paymentPayload' => $payment, 'paymentRequirements' => $req],
            'timeout' => 30,
            'max_duration' => 60,
        ])->toArray(false), $payment, $req);
    }
}
