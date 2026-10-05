<?php

namespace App\Tests\Command;

use App\Entity\Campaign;
use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

final class SendUnreadMessageRemindersCommandTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testReminderIsDelayedCoalescedAndSentOnlyOnceUntilMessagesAreRead(): void
    {
        [$companyOwner, $creatorOwner, $campaign, $conversation] = $this->createConversation();
        $oldMessage = new CampaignMessage($conversation, $companyOwner, 'An older message');
        $recentMessage = new CampaignMessage($conversation, $companyOwner, 'A more recent message');
        $this->entityManager->persist($oldMessage);
        $this->entityManager->persist($recentMessage);
        $this->entityManager->flush();
        $this->setMessageCreatedAt($oldMessage, new DateTimeImmutable('-90 minutes'));
        $this->setMessageCreatedAt($recentMessage, new DateTimeImmutable('-30 minutes'));

        $command = $this->commandTester();
        self::assertSame(0, $command->execute([]));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('creator@example.test', $email->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($email, 'Summer campaign');
        self::assertEmailHtmlBodyContains($email, 'Company Studio');
        self::assertEmailHtmlBodyContains($email, '2');
        self::assertStringNotContainsString('An older message', (string) $email->getHtmlBody());
        self::assertNotNull($conversation->getUnreadReminderSentAt($creatorOwner));

        self::assertSame(0, $command->execute([]));
        self::assertEmailCount(1);

        $oldMessage->markRead();
        $recentMessage->markRead();
        self::assertTrue($conversation->clearUnreadReminder($creatorOwner));
        $this->entityManager->flush();

        $newMessage = new CampaignMessage($conversation, $companyOwner, 'A later message');
        $this->entityManager->persist($newMessage);
        $this->entityManager->flush();
        $this->setMessageCreatedAt($newMessage, new DateTimeImmutable('-65 minutes'));
        self::assertSame(0, $command->execute([]));
        self::assertEmailCount(2);
        $newEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $newEmail);
        self::assertSame('creator@example.test', $newEmail->getTo()[0]->getAddress());
    }

    public function testReadMessagesAreNotEmailedAndMessagesUnderOneHourAreNotDue(): void
    {
        [$companyOwner, , , $conversation] = $this->createConversation();
        $readMessage = new CampaignMessage($conversation, $companyOwner, 'Already read');
        $recentMessage = new CampaignMessage($conversation, $companyOwner, 'Not yet an hour old');
        $readMessage->markRead();
        $this->entityManager->persist($readMessage);
        $this->entityManager->persist($recentMessage);
        $this->entityManager->flush();
        $this->setMessageCreatedAt($readMessage, new DateTimeImmutable('-2 hours'));
        $this->setMessageCreatedAt($recentMessage, new DateTimeImmutable('-45 minutes'));

        self::assertSame(0, $this->commandTester()->execute([]));
        self::assertEmailCount(0);
    }

    /**
     * @return array{User, User, Campaign, CampaignConversation}
     */
    private function createConversation(): array
    {
        $companyOwner = new User('company@example.test', 'ROLE_COMPANY');
        $companyOwner->setPassword('unused-test-hash');
        $company = new Company('reminder-company', 'Company Studio', 'Food');
        $companyOwner->setCompany($company);
        $creatorOwner = new User('creator@example.test', 'ROLE_CREATOR');
        $creatorOwner->setPassword('unused-test-hash');
        $creatorOwner->setPreferredLocale('en');
        $creator = new Creator('reminder-creator', 'Avery Creator', 'Food', 'Sarajevo', '', [], []);
        $creatorOwner->setCreator($creator);
        $campaign = new Campaign(
            'reminder-campaign',
            'Summer campaign',
            'A summer storytelling campaign.',
            'Create a short summer story.',
            'Food',
            ['Instagram'],
            ['One post'],
            100,
            500,
            'Sarajevo',
            1,
            new DateTimeImmutable('+30 days'),
            new DateTimeImmutable(),
            $company,
        );
        $conversation = new CampaignConversation($campaign, $creator, $companyOwner);
        $this->entityManager->persist($companyOwner);
        $this->entityManager->persist($creatorOwner);
        $this->entityManager->persist($campaign);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return [$companyOwner, $creatorOwner, $campaign, $conversation];
    }

    private function setMessageCreatedAt(CampaignMessage $message, DateTimeImmutable $createdAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE campaign_message SET created_at = :createdAt WHERE id = :id',
            [
                'createdAt' => $createdAt,
                'id' => $message->getId(),
            ],
            [
                'createdAt' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(static::$kernel);

        return new CommandTester($application->find('app:send-unread-message-reminders'));
    }
}
