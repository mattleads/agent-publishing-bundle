<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Command;

use MattLeads\AgentPublishingBundle\Scheduler\ScheduledPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'agent-publishing:publish-scheduled', description: 'Publish every scheduled article whose publish time has passed. Run it from cron.')]
final class PublishScheduledCommand extends Command
{
    public function __construct(private readonly ScheduledPublisher $publisher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $published = $this->publisher->publishDue();

        foreach ($published as $article) {
            $io->writeln(\sprintf('Published %s "%s" (%s/%s)', $article->getId(), $article->getTitle(), $article->getSection(), $article->getLocale()));
        }
        $io->success(\sprintf('%d article(s) published.', \count($published)));

        return Command::SUCCESS;
    }
}
