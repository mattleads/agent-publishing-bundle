<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Command;

use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Model\Scope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'agent-publishing:api-key:list', description: 'List API keys. Secrets are never shown.')]
final class ListApiKeysCommand extends Command
{
    public function __construct(private readonly ApiKeyStorageInterface $keys)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($this->keys->all() as $key) {
            $rows[] = [
                $key->getId(),
                $key->getName(),
                implode(', ', array_map(static fn(Scope $s) => $s->value, $key->getScopes())),
                [] === $key->getSections() ? 'all' : implode(', ', $key->getSections()),
                $key->getExpiresAt()?->format(\DateTimeInterface::ATOM) ?? 'never',
                $key->getRevokedAt()?->format(\DateTimeInterface::ATOM) ?? '-',
                $key->getLastUsedAt()?->format(\DateTimeInterface::ATOM) ?? '-',
            ];
        }
        if ([] === $rows) {
            $io->writeln('No keys.');

            return Command::SUCCESS;
        }
        $io->table(['Id', 'Name', 'Scopes', 'Sections', 'Expires', 'Revoked', 'Last used'], $rows);

        return Command::SUCCESS;
    }
}
