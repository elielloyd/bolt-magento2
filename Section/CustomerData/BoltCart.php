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
 *
 * @copyright  Copyright (c) 2017-2022 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Section\CustomerData;

use Bolt\Boltpay\Helper\Cart as CartHelper;
use Magento\Customer\CustomerData\SectionSourceInterface;

class BoltCart implements SectionSourceInterface
{
    /**
     * @var CartHelper
     */
    private $cartHelper;

    public function __construct(CartHelper $cartHelper)
    {
        $this->cartHelper = $cartHelper;
    }

    public function getSectionData()
    {
        return $this->cartHelper->calculateCartAndHints();
    }
}
