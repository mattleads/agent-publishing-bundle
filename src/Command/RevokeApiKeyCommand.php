<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Command;

use MattLeads\AgentPublishingBundle\Contract\ApiKeyStorageInterface;
use MattLeads\AgentPublishingBundle\Model\ApiKey;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'agent-publishing:api-key:revoke', description: 'Revoke an API key by id or name. It stops working on the next request.')]
final class RevokeApiKeyCommand extends Command
{
    public function __construct(private readonly ApiKeyManager $manager, private readonly ApiKeyStorageInterface $keys)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Key id, or the name it was issued with');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $reference */
        $reference = $input->getArgument('key');

        $key = $this->keys->findById($reference);
        if (null === $key) {
            $named = array_values(array_filter($this->keys->all(), static fn(ApiKey $k) => $k->getName() === $reference && !$k->isRevoked()));
            if (\count($named) > 1) {
                $io->error(\sprintf('%d active keys are named "%s". Revoke one by id (see agent-publishing:api-key:list).', \count($named), $reference));

                return Command::INVALID;
            }
            $key = $named[0] ?? null;
        }
        if (null === $key) {
            $io->error(\sprintf('No key "%s".', $reference));

            return Command::FAILURE;
        }
        if ($key->isRevoked()) {
            $io->note(\sprintf('Key "%s" (id %s) was already revoked.', $key->getName(), $key->getId()));

            return Command::SUCCESS;
        }
        $this->manager->revoke($key);
        $io->success(\sprintf('Key "%s" (id %s) revoked.', $key->getName(), $key->getId()));

        return Command::SUCCESS;
    }
}
