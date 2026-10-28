<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;

final class EchoTool
{
    // ponytail: free tool to prove the MCP wiring; paid tools come with the x402 middleware.
    #[McpTool(name: 'echo', description: 'Returns the given text unchanged.')]
    public function __invoke(string $text): string
    {
        return $text;
    }
}
