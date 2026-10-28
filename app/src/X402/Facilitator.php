<?php

declare(strict_types=1);

namespace App\X402;

use Psr\Log\LoggerInterface;
use Quebracho\X402\Settlement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Settles paid calls. Only mock mode exists yet, and it must be asked for:
 * X402_FACILITATOR=mock. Anything else makes every /mcp request fail
 * (the controller cannot be built) instead of serving paid tools for free.
 */
final class Facilitator
{
    public function __construct(
        #[Autowire(env: 'X402_FACILITATOR')] string $mode,
        private readonly LoggerInterface $logger,
    ) {
        if ('mock' !== $mode) {
            throw new \LogicException(\sprintf('X402_FACILITATOR="%s": only "mock" is implemented.', $mode));
        }
    }

    public function __invoke(array $payment, array $req): array
    {
        $this->logger->warning('x402 mock settlement: signature verified, nothing moved on chain.', ['network' => $req['network'], 'amount' => $req['amount']]);

        return Settlement::run(null, $payment, $req);
    }
}
