<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_astroapi
 * @copyright   (C) 2026
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\Module\AstroApi\Site\Helper\AstroApiHelper;

/** @var Joomla\Registry\Registry $params */
/** @var stdClass $module */

try {
    // Load helper
    require_once __DIR__ . '/helper.php';
    
    // Get the helper class
    $helper = new AstroApiHelper();
    
    // Fetch data from AstroAPI
    $items = $helper->getAstroObjects(
        $params->get('astroapi_url'),
        $params->get('items_per_page', 10),
        $params->get('cache_time', 3600)
    );
    
    // Get display mode
    $displayMode = $params->get('display_mode', 'list');
    
    // Load layout
    $layout = $params->get('layout', 'default');
    
} catch (Exception $e) {
    $items = [];
    $displayMode = 'list';
    $layout = 'default';
    Factory::getApplication()->enqueueMessage(
        'AstroAPI Error: ' . $e->getMessage(),
        'error'
    );
}

require ModuleHelper::getLayoutPath('mod_astroapi', $layout);



