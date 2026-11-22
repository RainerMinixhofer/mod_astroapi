<?php

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use RainerMinixhofer\Plugin\System\AstroapiPhocaSync\Extension\AstroapiPhocaSync;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(AstroapiPhocaSync::class, static function (Container $container): AstroapiPhocaSync {
                $plugin = new AstroapiPhocaSync((array) PluginHelper::getPlugin(
                    'system',
                    'astroapi_phoca_sync'
                ));
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            })
        );
    }
};
