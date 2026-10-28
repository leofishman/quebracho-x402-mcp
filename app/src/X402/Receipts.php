<?php

declare(strict_types=1);

namespace App\X402;

/**
 * The x402 receipts the buyer got during this run, for app:ask to show.
 */
final class Receipts
{
    /** @var list<array{tool: string, req: array, receipt: array}> */
    public array $all = [];

    public function __invoke(string $tool, array $req, array $receipt): void
    {
        $this->all[] = ['tool' => $tool, 'req' => $req, 'receipt' => $receipt];
    }
}
