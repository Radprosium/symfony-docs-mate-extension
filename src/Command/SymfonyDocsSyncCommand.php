<?php

declare(strict_types=1);

namespace Rad\SymfonyDocsMateExtension\Command;

use Rad\SymfonyDocsMateExtension\Service\DocsSourceResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'symfony-docs-mate:sync',
    description: 'Prefetch or refresh the managed Symfony docs snapshot used by the Mate extension.',
)]
final class SymfonyDocsSyncCommand extends Command
{
    public function __construct(
        private readonly DocsSourceResolver $sourceResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force a fresh snapshot download even when a cached snapshot already exists.')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'Override the docs ref to download for this sync run only.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceInfo = $this->sourceResolver->syncManagedSnapshot(
            (bool) $input->getOption('force'),
            is_string($input->getOption('ref')) ? $input->getOption('ref') : null,
        );

        if ('explicit' === $sourceInfo['mode']) {
            $io->success(\sprintf('Using explicit Symfony docs checkout at "%s".', $sourceInfo['docs_root']));

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Symfony docs snapshot is ready at "%s" (ref: %s).',
            $sourceInfo['docs_root'],
            $sourceInfo['docs_ref'] ?? 'unknown',
        ));

        $io->definitionList(
            ['Mode' => $sourceInfo['mode']],
            ['Docs root' => $sourceInfo['docs_root']],
            ['Ref' => $sourceInfo['docs_ref'] ?? ''],
            ['Archive URL' => $sourceInfo['archive_url'] ?? ''],
            ['Updated at' => $sourceInfo['updated_at'] ?? ''],
        );

        return Command::SUCCESS;
    }
}
