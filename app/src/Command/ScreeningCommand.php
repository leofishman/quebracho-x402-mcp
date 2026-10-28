<?php

declare(strict_types=1);

namespace App\Command;

use App\X402\Screening;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The admin's side of address screening: sync OFAC, and keep our own lists.
 */
#[AsCommand(name: 'app:screening', description: 'Address screening: sync | allow | deny | forget | check | list')]
final class ScreeningCommand
{
    public function __construct(private readonly Screening $screening)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('sync, allow, deny, forget, check or list', suggestedValues: ['sync', 'allow', 'deny', 'forget', 'check', 'list'])] string $action,
        #[Argument('The address (0x… on EVM, an account ID on NEAR)')] string $address = '',
    ): int {
        if (\in_array($action, ['allow', 'deny', 'forget', 'check'], true) && !self::isAddress($address)) {
            $io->error("\"$action\" needs an address: 0x + 40 hex on EVM, a NEAR account ID.");

            return Command::INVALID;
        }

        switch ($action) {
            case 'sync':
                $io->success(\sprintf('OFAC lists synced: %d addresses (%s).', $this->screening->sync(), implode(', ', Screening::OFAC_LISTS)));
                break;
            case 'allow':
                $this->screening->set($address, 'allow');
                $io->warning("$address passes screening, even if OFAC lists it. That is now your call.");
                break;
            case 'deny':
            case 'forget':
                $this->screening->set($address, 'deny' === $action ? 'deny' : null);
                $io->success("$address: ".('deny' === $action ? 'denied.' : 'off our lists.'));
                break;
            case 'check':
                $io->writeln("$address: ".($this->screening->reason($address) ?? 'passes'));
                break;
            case 'list':
                $io->writeln(json_encode($this->screening->own(), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
                break;
            default:
                $io->error("Unknown action \"$action\".");

                return Command::INVALID;
        }

        return Command::SUCCESS;
    }

    /**
     * An EVM address, or a NEAR account ID (named, or 64-hex implicit): a
     * typo stored here would protect nothing.
     */
    private static function isAddress(string $address): bool
    {
        return 1 === preg_match('/^0x[0-9a-fA-F]{40}$/', $address)
            || (!str_starts_with($address, '0x') && 1 === preg_match('/^(([a-z0-9]+[-_])*[a-z0-9]+\.)*([a-z0-9]+[-_])*[a-z0-9]+$/', $address) && \strlen($address) >= 2 && \strlen($address) <= 64);
    }
}
