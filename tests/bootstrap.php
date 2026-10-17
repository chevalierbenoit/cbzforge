<?php

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Create test database schema (drop & recreate with SQLite)
$kernel = new App\Kernel('test', true);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
$metadata = $em->getMetadataFactory()->getAllMetadata();
if (!empty($metadata)) {
    $schemaTool = new SchemaTool($em);
    $schemaTool->dropDatabase();
    if (!file_exists($dir = dirname(__DIR__).'/var')) {
        mkdir($dir, 0777, true);
    }
    $schemaTool->createSchema($metadata);
}
$kernel->shutdown();
