<?php
declare(strict_types=1);

namespace ScriptFUSION\Steam250\Curator;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('sync')
            ->setDescription('Synchronises apps in a database with Steam curator reviews.')
            ->addArgument('db', InputArgument::REQUIRED, 'Path to database.')
            ->addArgument('curator id', InputArgument::REQUIRED, 'Curator ID.')
            ->addArgument('username', InputArgument::REQUIRED, 'Steam username.')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Steam password.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $synchroniser = (new ReviewSynchroniserFactory)->create(
            $input->getArgument('db'),
            $input->getArgument('curator id'),
            $input->getArgument('username'),
            $input->getOption('password')
        );

        return $synchroniser->synchronize() ? 0 : 1;
    }
}
