<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Command;

use MattLeads\AgentPublishingBundle\Model\Scope;
use MattLeads\AgentPublishingBundle\Section\SectionRegistry;
use MattLeads\AgentPublishingBundle\Security\ApiKeyManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'agent-publishing:api-key:create', description: 'Issue an API key for an agent or platform. The secret is shown once.')]
final class CreateApiKeyCommand extends Command
{
    public function __construct(private readonly ApiKeyManager $keys, private readonly SectionRegistry $sections)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Who holds the key, e.g. "marketcells" or "seo-agent"')
            ->addOption('scope', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Scope to grant (repeatable). One of: ' . implode(', ', Scope::values()), ['articles:read', 'articles:draft'])
            ->addOption('section', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict the key to a section (repeatable). Default: all sections.')
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Expiry as an ISO 8601 date-time or a relative time like "+90 days".');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var list<string> $scopeNames */
        $scopeNames = $input->getOption('scope');
        /** @var list<string> $sections */
        $sections = $input->getOption('section');

        $scopes = [];
        foreach ($scopeNames as $scopeName) {
            $scopes[] = Scope::tryFrom($scopeName) ?? (static function () use ($io, $scopeName) {
                $io->error(\sprintf('Unknown scope "%s". Available: %s.', $scopeName, implode(', ', Scope::values())));

                return null;
            })();
        }
        if (\in_array(null, $scopes, true)) {
            return Command::INVALID;
        }
        foreach ($sections as $section) {
            if (null === $this->sections->get($section)) {
                $io->error(\sprintf('Unknown section "%s".', $section));

                return Command::INVALID;
            }
        }

        $expiresAt = null;
        /** @var ?string $expires */
        $expires = $input->getOption('expires');
        if (null !== $expires) {
            try {
                $expiresAt = new \DateTimeImmutable($expires);
            } catch (\Exception) {
                $io->error(\sprintf('Cannot parse expiry "%s".', $expires));

                return Command::INVALID;
            }
        }

        /** @var list<Scope> $scopes */
        $issued = $this->keys->issue($name, $scopes, $sections, $expiresAt);
        $io->success(\sprintf('Key "%s" issued (id %s).', $name, $issued['key']->getId()));
        $io->writeln('Secret (shown once):');
        $io->writeln('');
        $io->writeln('  ' . $issued['secret']);
        $io->writeln('');
        $io->writeln('Scopes: ' . implode(', ', array_map(static fn(Scope $s) => $s->value, $scopes)));
        $io->writeln('Sections: ' . ([] === $sections ? 'all' : implode(', ', $sections)));
        $io->writeln('Expires: ' . ($expiresAt?->format(\DateTimeInterface::ATOM) ?? 'never'));

        return Command::SUCCESS;
    }
}
