<?php

declare(strict_types=1);

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use App\Entity\Application;
use App\Entity\Offer;
use App\Entity\CampaignConversation;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
(new Dotenv())->bootEnv($root.'/.env');
$url = parse_url($_SERVER['DATABASE_URL'] ?? '');
if (($_SERVER['APP_ENV'] ?? '') !== 'browser'
    || !in_array($url['host'] ?? '', ['127.0.0.1', 'localhost'], true)
    || ($url['path'] ?? '') !== '/wave_browser_e2e'
    || empty($_SERVER['WAVE_E2E_PASSWORD'])
) {
    throw new RuntimeException('Browser fixtures require APP_ENV=browser and the dedicated local wave_browser_e2e database.');
}
$kernel = new Kernel('browser', false);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
assert($em instanceof EntityManagerInterface);
if ($em->getConnection()->getDatabase() !== 'wave_browser_e2e') {
    throw new RuntimeException('Refusing to reset a database other than wave_browser_e2e.');
}
$schema = new SchemaTool($em);
$metadata = $em->getMetadataFactory()->getAllMetadata();
$schema->dropSchema($metadata);
$schema->createSchema($metadata);
$companyUser = new User('browser-company@example.test', 'ROLE_COMPANY', true);
$creatorUser = new User('browser-creator@example.test', 'ROLE_CREATOR', true);
foreach ([$companyUser, $creatorUser] as $user) {
    $user->setPassword(password_hash($_SERVER['WAVE_E2E_PASSWORD'], PASSWORD_BCRYPT, ['cost' => 4]));
    $user->setEmailVerified(true);
    $user->setHideMyAccount(false);
    $user->setPhone('+38761123456');
    $user->setCity('Sarajevo');
    $user->setCountryCode('BA');
    $user->setPreferredLocale('bs');
    $em->persist($user);
}
$company = new Company('browser-brand', 'Browser Brand', 'Travel');
$creator = new Creator('browser-creator', 'Browser Creator', 'Travel', 'Sarajevo', 'A complete profile for the isolated browser regression.', [], []);
$companyUser->setCompany($company);
$creatorUser->setCreator($creator);
$campaign = new Campaign(
    'browser-negotiation', 'Browser negotiation campaign', 'A real two-browser negotiation regression.',
    '<p>One short video and five photographs, with terms agreed in chat.</p>', 'Travel',
    ['Instagram', 'TikTok'], ['One short video', 'Five photographs'], 300, 800, 'Sarajevo', 3,
    new DateTimeImmutable('+30 days'), new DateTimeImmutable('-1 day'), $company, countryCode: 'BA',
);
$em->persist($campaign);
$workCampaign = new Campaign('browser-work-review', 'Browser work review', 'A hired creator submits and revises work.', '<p>Deliver the agreed video and photographs.</p>', 'Travel', ['Instagram'], ['Video'], 300, 800, 'Sarajevo', 1, new DateTimeImmutable('+30 days'), new DateTimeImmutable(), $company, countryCode: 'BA');
$workApplication = new Application($workCampaign, $creator, 'A confirmed collaboration for work review.');
$workOffer = new Offer($workApplication, 400, 'Agreed work for the browser review test.', new DateTimeImmutable('+14 days'));
$workOffer->respond('accepted');
$workConversation = new CampaignConversation($workCampaign, $creator, $companyUser);
foreach ([$workCampaign, $workApplication, $workOffer, $workConversation] as $entity) $em->persist($entity);
$em->flush();
echo json_encode(['campaignSlug' => $campaign->getSlug(), 'companyId' => $companyUser->getId(), 'creatorId' => $creatorUser->getId(), 'workCampaignId' => $workCampaign->getId(), 'workConversationId' => $workConversation->getId(), 'workOfferId' => $workOffer->getId()], JSON_THROW_ON_ERROR), PHP_EOL;
$kernel->shutdown();
