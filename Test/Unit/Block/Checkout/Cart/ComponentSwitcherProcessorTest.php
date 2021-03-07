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
 * @copyright  Copyright (c) 2017-2021 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Test\Unit\Block\Checkout\Cart;

use Bolt\Boltpay\Block\Checkout\Cart\ComponentSwitcherProcessor;
use Bolt\Boltpay\Helper\Config as ConfigHelper;
use Bolt\Boltpay\Model\Api\Data\BoltConfigSettingFactory;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Module\ResourceInterface;
use Bolt\Boltpay\Test\Unit\BoltTestCase;
use PHPUnit_Framework_MockObject_MockObject;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Composer\ComposerFactory;
use Bolt\Boltpay\Model\EventsForThirdPartyModules;

/**
 * @coversDefaultClass \Bolt\Boltpay\Block\Checkout\Cart\ComponentSwitcherProcessor
 */
class ComponentSwitcherProcessorTest extends BoltTestCase
{
    /**
     * Mocked instance of config helper
     *
     * @var PHPUnit_Framework_MockObject_MockObject|ConfigHelper
     */
    protected $configHelper;

    /**
     * Mocked instance of the Config Helper context
     *
     * @var PHPUnit_Framework_MockObject_MockObject|Context
     */
    protected $helperContextMock;

    /**
     * Mocked instance of the eventsForThirdPartyModulesMock
     *
     * @var PHPUnit_Framework_MockObject_MockObject|Context
     */
    protected $eventsForThirdPartyModulesMock;

    /**
     * Mocked instance of the class being tested
     *
     * @var PHPUnit_Framework_MockObject_MockObject|ComponentSwitcherProcessor
     */
    protected $currentMock;

    /**
     * Setup test dependencies
     *
     * @return void
     */
    protected function setUpInternal()
    {
        $this->helperContextMock = $this->createMock(Context::class);
        $this->configHelper = $this->getMockBuilder(ConfigHelper::class)
            ->setMethods(['useStoreCreditConfig', 'useRewardPointsConfig', 'useAmastyStoreCreditConfig'])
            ->setConstructorArgs(
                [
                    $this->helperContextMock,
                    $this->createMock(EncryptorInterface::class),
                    $this->createMock(ResourceInterface::class),
                    $this->createMock(ProductMetadataInterface::class),
                    $this->createMock(BoltConfigSettingFactory::class),
                    $this->createMock(RegionFactory::class),
                    $this->createMock(ComposerFactory::class)
                ]
            )
            ->getMock();
        $this->eventsForThirdPartyModulesMock = $this->createPartialMock(EventsForThirdPartyModules::class, ['runFilter']);
        $this->currentMock = $this->getMockBuilder(ComponentSwitcherProcessor::class)
            ->setMethods()
            ->setConstructorArgs(
                [
                    $this->configHelper,
                    $this->eventsForThirdPartyModulesMock
                ]
            )
            ->getMock();
    }

    /**
     * @test
     * Test process method filters the provided JS layout using the third party filter "filterProcessLayout"
     *
     * @covers ::process
     */
    public function process_withVariousConfigurationStates_returnsModifiedJsLayout() {
        $filteredJsLayout = [
            'components' => [
                'block-totals' => [
                    'children' => [
                        'storeCredit'         => [
                            'component' => 'Magento_CustomerBalance/js/view/payment/customer-balance',
                        ],
                        'rewardPoints'        => [
                            'component' => 'Magento_Reward/js/view/payment/reward',
                        ]
                    ]
                ]
            ]
        ];
        $originalJsLayout = [];
        $this->eventsForThirdPartyModulesMock->expects(static::once())
            ->method('runFilter')
            ->with('filterProcessLayout', $originalJsLayout)
            ->willReturn($filteredJsLayout);
        static::assertEquals($filteredJsLayout, $this->currentMock->process($originalJsLayout));
    }
}
