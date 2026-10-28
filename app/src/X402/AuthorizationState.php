<?php

declare(strict_types=1);

namespace App\X402;

use Quebracho\X402\Networks;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whether an EIP-3009 authorization was used on chain: the token's
 * authorizationState(from, nonce), read through the network's public RPC.
 * The buyer's Budget asks it before freeing an expired, unsettled signature.
 */
final class AuthorizationState
{
    private const SELECTOR = '0xe94a0102'; // authorizationState(address,bytes32)

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function __invoke(string $network, string $from, string $nonce): bool
    {
        if (!preg_match('/^0x[0-9a-fA-F]{40}$/', $from) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $nonce)) {
            throw new \InvalidArgumentException('Not an EIP-3009 from/nonce.');
        }
        $token = Networks::get($network);
        $data = self::SELECTOR.str_pad(substr($from, 2), 64, '0', \STR_PAD_LEFT).substr($nonce, 2);
        $out = $this->http->request('POST', $token['rpc'], [
            'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_call', 'params' => [['to' => $token['asset'], 'data' => $data], 'latest']],
            'timeout' => 5,
            'max_duration' => 5,
        ])->toArray();
        $result = $out['result'] ?? null;
        if (!\is_string($result) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $result)) {
            throw new \UnexpectedValueException('eth_call failed: '.json_encode($out['error'] ?? $out));
        }

        return 0 !== gmp_cmp(gmp_init(substr($result, 2), 16), 0);
    }
}
