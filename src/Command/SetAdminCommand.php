<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:admin', description: 'Grant or revoke Wave admin dashboard access.')]
final class SetAdminCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the existing account')
            ->addArgument('decision', InputArgument::REQUIRED, 'grant or revoke admin access');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $decision = $input->getArgument('decision');
        if (!in_array($decision, ['grant', 'revoke'], true)) {
            $io->error('Decision must be either "grant" or "revoke".');

            return Command::INVALID;
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error('Account not found.');

            return Command::FAILURE;
        }

        $user->setAdmin($decision === 'grant');
        $this->entityManager->flush();
        $io->success(sprintf('Admin dashboard access was %s for "%s".', $decision === 'grant' ? 'granted' : 'revoked', $email));

        return Command::SUCCESS;
    }
}
