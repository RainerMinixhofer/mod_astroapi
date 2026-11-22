<?php

/**
 * @package     Joomla.Site
 * @subpackage  mod_astroapi
 * @copyright   (C) 2026
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var array $items */
/** @var string $displayMode */
/** @var Joomla\Registry\Registry $params */

?>
<div class="mod-astroapi<?php echo $params->get('moduleclass_sfx'); ?>">
    <?php if (empty($items)): ?>
        <div class="alert alert-info">
            <?php echo Text::_('MOD_ASTROAPI_NO_ITEMS'); ?>
        </div>
    <?php else: ?>
        <?php if ($displayMode === 'gallery'): ?>
            <!-- Gallery View -->
            <div class="astroapi-gallery">
                <div class="row">
                    <?php foreach ($items as $item): ?>
                        <div class="col-md-4 mb-4">
                            <div class="card h-100">
                                <?php if (!empty($item['image'])): ?>
                                    <img src="<?php echo htmlspecialchars($item['image']); ?>" 
                                         class="card-img-top" alt="<?php echo htmlspecialchars($item['name'] ?? 'Object'); ?>">
                                <?php endif; ?>
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <?php echo htmlspecialchars($item['name'] ?? 'Unknown'); ?>
                                    </h5>
                                    <p class="card-text">
                                        <?php echo htmlspecialchars(substr($item['description'] ?? '', 0, 100)); ?>...
                                    </p>
                                </div>
                                <div class="card-footer">
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($item['observation_time'] ?? $item['type'] ?? ''); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ($displayMode === 'map'): ?>
            <!-- Map View (requires additional setup) -->
            <div class="astroapi-map" id="astroapi-map">
                <p><?php echo Text::_('MOD_ASTROAPI_MAP_VIEW'); ?></p>
            </div>
        <?php else: ?>
            <!-- List View (default) -->
            <div class="astroapi-list">
                <ul class="list-group">
                    <?php foreach ($items as $item): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">
                                        <?php echo htmlspecialchars($item['name'] ?? 'Unknown'); ?>
                                    </h6>
                                    <p class="mb-1 small text-muted">
                                        <?php echo htmlspecialchars($item['observation_time'] ?? $item['type'] ?? ''); ?>
                                    </p>
                                    <p class="mb-0 small">
                                        <?php echo htmlspecialchars(substr($item['description'] ?? '', 0, 150)); ?>
                                        <?php if (strlen($item['description'] ?? '') > 150): ?>...<?php endif; ?>
                                    </p>
                                </div>
                                <?php if (!empty($item['image'])): ?>
                                    <div class="text-end" style="flex-shrink: 0; margin-left: 1rem;">
                                        <img src="<?php echo htmlspecialchars($item['image']); ?>" 
                                             alt="<?php echo htmlspecialchars($item['name'] ?? 'Object'); ?>"
                                             style="max-width: 60px; max-height: 60px; border-radius: 4px;">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
