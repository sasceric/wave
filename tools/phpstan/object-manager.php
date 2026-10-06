<?php

use App\Doctrine\JsonText;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Metadata analysis only: no real database credentials or connection are needed.
$config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true);
$config->enableNativeLazyObjects(true);
$config->setCustomStringFunctions(['JSON_TEXT' => JsonText::class]);
$connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'dbname' => 'wave_static_analysis', 'serverVersion' => '17']);

return new EntityManager($connection, $config);
