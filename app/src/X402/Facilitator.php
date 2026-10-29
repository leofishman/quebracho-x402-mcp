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
        if ('mock' !== $mode && ('https' !== parse_url($mode, \PHP_URL_SCHEME) || !parse_url($mode, \PHP_URL_HOST))) {
            throw new \LogicException(\sprintf('X402_FACILITATOR="%s": "mock" or an https:// facilitator URL.', $mode));
        }
    }

    public function __invoke(array $payment, array $req): array
    {
        if ('mock' === $this->mode) {
            $this->logger->warning('x402 mock settlement: signature verified, nothing moved on chain.', ['network' => $req['network'], 'amount' => $req['amount']]);

            return Settlement::run(null, $payment, $req);
        }

        $receipt = Settlement::run(fn (): array => $this->settle($payment, $req), $payment, $req);
        if (true !== $receipt['success']) {
            // The tool ran and its output is withheld: enough to reconcile with authorizationState(from, nonce).
            $auth = $payment['payload']['authorization'] ?? [];
            $this->logger->error('x402 settlement failed.', ['receipt' => $receipt, 'payer' => $auth['from'] ?? null, 'nonce' => $auth['nonce'] ?? null]);
        }

        return $receipt;
    }

    private function settle(array $payment, array $req): array
    {
        try {
            $response = $this->http->request('POST', rtrim($this->mode, '/').'/settle', [
                'json' => ['x402Version' => 2, 'paymentPayload' => $payment, 'paymentRequirements' => $req],
                'timeout' => 30,
                'max_duration' => 60,
                // The signed payment goes to this host only.
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $out = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('x402 facilitator unreachable or unreadable.', ['exception' => $e]);
            throw $e;
        }
        $tx = $out['transaction'] ?? null;
        // A success is a 2xx naming a real transaction on this network for this payer.
        $ok = $status >= 200 && $status < 300
            && \is_string($tx) && preg_match('/^0x[0-9a-fA-F]{64}$/', $tx)
            && ($out['network'] ?? null) === $req['network']
            && (!isset($out['payer']) || 0 === strcasecmp((string) $out['payer'], (string) ($payment['payload']['authorization']['from'] ?? '')));
        if (($out['success'] ?? null) === true && !$ok) {
            return ['success' => false, 'errorReason' => 'invalid_settle_response', 'transaction' => \is_string($tx) ? $tx : ''];
        }

        return $out;
    }
}
