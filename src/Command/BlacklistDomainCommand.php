<?php

namespace App\Command;

use App\Entity\BlacklistedDomain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: "app:blacklist-domain",
    description: "Add a domain to the blacklist",
)]
class BlacklistDomainCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument("domain", InputArgument::REQUIRED, "The domain to blacklist (e.g. badsite.com)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $domainArg = $input->getArgument("domain");

        $domain = new BlacklistedDomain();
        $domain->setDomain($domainArg);

        $this->entityManager->persist($domain);
        $this->entityManager->flush();

        $io->success(sprintf("Domain %s has been blacklisted.", $domainArg));

        return Command::SUCCESS;
    }
}
