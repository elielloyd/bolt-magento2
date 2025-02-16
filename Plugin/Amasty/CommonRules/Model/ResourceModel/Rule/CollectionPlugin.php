<?php
/**
 * Bolt magento2 plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2022 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Plugin\Amasty\CommonRules\Model\ResourceModel\Rule;

use Bolt\Boltpay\Helper\Hook as HookHelper;
use Bolt\Boltpay\Helper\Session as SessionHelper;

class CollectionPlugin
{
    /** @var SessionHelper */
    private $sessionHelper;
    
    public function __construct(
        SessionHelper $sessionHelper
    ) {
        $this->sessionHelper = $sessionHelper;
    }
    
    public function afterAddAddressFilter(\Amasty\CommonRules\Model\ResourceModel\Rule\Collection $subject, $result, $address)
    {
        if (HookHelper::$fromBolt && $this->sessionHelper->getCheckoutSession()->getBoltBackendOrderShippingRestrictionRule(false)) {
            $result->addFieldToFilter('for_admin', 1);
            $this->sessionHelper->getCheckoutSession()->setBoltBackendOrderShippingRestrictionRule(false);
        }
        
        return $result;
    }
}