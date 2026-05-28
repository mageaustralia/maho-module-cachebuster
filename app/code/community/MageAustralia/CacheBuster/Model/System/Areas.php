<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    MageAustralia_CacheBuster
 * @copyright  Copyright (c) 2026 Mage Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Source model for the "Areas" dropdown in System Config. Three choices:
 *   - frontend            (default  -  bust customer-facing pages only)
 *   - adminhtml           (admin-only; rarely useful)
 *   - frontend_adminhtml  (both)
 */
class MageAustralia_CacheBuster_Model_System_Areas
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $helper = Mage::helper('mageaustralia_cachebuster');
        return [
            ['value' => 'frontend',           'label' => $helper->__('Frontend only')],
            ['value' => 'adminhtml',          'label' => $helper->__('Adminhtml only')],
            ['value' => 'frontend_adminhtml', 'label' => $helper->__('Frontend + Adminhtml')],
        ];
    }
}
