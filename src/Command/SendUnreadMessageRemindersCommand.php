<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:send-unread-message-reminders',
    description: 'Email participants about campaign messages that have been unread for one hour.',
)]
final class SendUnreadMessageRemindersCommand extends Command
{
    public function __construct(
        private readonly \App\Background\ReminderBatch $batch,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = $this->batch->run(false);

        $output->writeln(sprintf('Sent %d unread message reminder(s).', $sent));

        return Command::SUCCESS;
    }
}
