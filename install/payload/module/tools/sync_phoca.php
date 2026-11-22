<?php

declare(strict_types=1);

define('JOOMLA_ROOT', getenv('ASTROAPI_JOOMLA_ROOT') ?: '/var/www/joomla');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must be run from the command line.\n");
    exit(1);
}

define('_JEXEC', 1);
define('JPATH_BASE', JOOMLA_ROOT);

$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_METHOD'] ??= 'GET';

require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(Joomla\Session\Session::class, 'session.cli')
    ->alias(Joomla\Session\SessionInterface::class, 'session.cli');

$application = $container->get(Joomla\Console\Application::class);
Joomla\CMS\Factory::$application = $application;

$database = $container->get(Joomla\Database\DatabaseInterface::class);
$query = $database->createQuery()
    ->select($database->quoteName('params'))
    ->from($database->quoteName('#__extensions'))
    ->where($database->quoteName('type') . ' = ' . $database->quote('plugin'))
    ->where($database->quoteName('folder') . ' = ' . $database->quote('system'))
    ->where($database->quoteName('element') . ' = ' . $database->quote('astroapi_phoca_sync'))
    ->where($database->quoteName('enabled') . ' = 1');
$database->setQuery($query);
$params = $database->loadResult();

if (!is_string($params) || json_validate($params) === false) {
    throw new RuntimeException('The enabled astroapi_phoca_sync plugin configuration was not found.');
}

require_once JPATH_PLUGINS .
    '/system/astroapi_phoca_sync/src/Extension/AstroapiPhocaSync.php';

$plugin = new RainerMinixhofer\Plugin\System\AstroapiPhocaSync\Extension\AstroapiPhocaSync([
    'params' => new Joomla\Registry\Registry($params),
]);
$plugin->setApplication($application);
$plugin->setDatabase($database);

try {
    $result = $plugin->sync();
    printf(
        "AstroAPI Phoca sync completed: %d created, %d updated, %d skipped.\n",
        $result['created'],
        $result['updated'],
        $result['skipped']
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "AstroAPI Phoca sync failed: " . $exception->getMessage() . "\n"
    );
    exit(1);
}
