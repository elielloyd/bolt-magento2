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
 * @copyright  Copyright (c) 2020 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Test\Unit\Section\CustomerData;

use Bolt\Boltpay\Helper\Cart as CartHelper;
use Bolt\Boltpay\Section\CustomerData\BoltCart;
use Bolt\Boltpay\Test\Unit\BoltTestCase;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Class BoltCartTest
 *
 * @package Bolt\Boltpay\Test\Unit\Section\CustomerData
 * @coversDefaultClass \Bolt\Boltpay\Section\CustomerData\BoltCart
 */
class BoltCartTest extends BoltTestCase
{
    /**
     * @var CartHelper
     */
    private $cartHelper;

    /**
     * @var BoltCart
     */
    private $boltCart;

    /**
     * @inheritdoc
     */
    public function setUpInternal()
    {
        $this->cartHelper = $this->createMock(CartHelper::class);
        $this->boltCart = (new ObjectManager($this))->getObject(
            BoltCart::class,
            [
                'cartHelper' => $this->cartHelper
            ]
        );
    }

    /**
     * @test
     */
    public function getSectionData()
    {
        $this->cartHelper->expects(self::once())->method('calculateCartAndHints');
        $this->boltCart->getSectionData();
    }
}
