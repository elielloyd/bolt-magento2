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
 * @copyright  Copyright (c) 2017-2022 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Test\Unit\ViewModel;

use Bolt\Boltpay\Helper\Config;
use Bolt\Boltpay\Model\EventsForThirdPartyModules;
use Bolt\Boltpay\Test\Unit\BoltTestCase;
use Bolt\Boltpay\Test\Unit\TestHelper;
use Bolt\Boltpay\Test\Unit\TestUtils;
use Bolt\Boltpay\ViewModel\MinicartAddons;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use Magento\TestFramework\Helper\Bootstrap;
/**
 * @coversDefaultClass \Bolt\Boltpay\ViewModel\MinicartAddons
 */
class MinicartAddonsTest extends BoltTestCase
{
    const DEFAULT_LAYOUT = [
        [
            'parent'    => 'minicart_content.extra_info',
            'name'      => 'minicart_content.extra_info.rewards',
            'component' => 'Magento_Reward/js/view/payment/reward',
            'config'    => [],
        ],
        [
            'parent'    => 'minicart_content.extra_info',
            'name'      => 'minicart_content.extra_info.rewards_total',
            'component' => 'Magento_Reward/js/view/cart/reward',
            'config'    => [
                'template' => 'Magento_Reward/cart/reward',
                'title'    => 'Reward Points',
            ],
        ]
    ];

    /**
     * @var \Bolt\Boltpay\Helper\Config|MockObject
     */
    private $configHelper;

    /**
     * @var \Magento\Framework\Serialize\SerializerInterface
     */
    private $serializer;

    /**
     * @var \Magento\Framework\App\Http\Context|MockObject
     */
    private $httpContext;

    /**
     * @var MinicartAddons|MockObject
     */
    private $currentMock;

    /**
     * @var EventsForThirdPartyModules|MockObject
     */
    private $eventsForThirdPartyModulesMock;


    private $minicartAddons;

    private $objectManager;

    /**
     * Setup test dependencies, called before each test
     */
    public function setUpInternal()
    {
        if (!class_exists('\Magento\TestFramework\Helper\Bootstrap')) {
            return;
        }
        $this->objectManager = Bootstrap::getObjectManager();

        $this->configHelper = $this->objectManager->create(\Bolt\Boltpay\Helper\Config::class);
        $this->serializer = $this->objectManager->create(\Magento\Framework\Serialize\Serializer\Json::class);
        $this->httpContext = $this->objectManager->create(\Magento\Framework\App\Http\Context::class);
        $this->eventsForThirdPartyModulesMock = $this->createMock(EventsForThirdPartyModules::class);

        $this->minicartAddons = new MinicartAddons(
            $this->serializer,
            $this->httpContext,
            $this->configHelper,
            $this->eventsForThirdPartyModulesMock
        );
    }

    /**
     * @test
     * that __construct always sets internal properties appropriately
     *
     * @covers ::__construct
     */
    public function __construct_always_setsInternalProperties()
    {
        $instance = new \Bolt\Boltpay\ViewModel\MinicartAddons(
            $this->serializer,
            $this->httpContext,
            $this->configHelper,
            $this->eventsForThirdPartyModulesMock
        );
        static::assertAttributeEquals($this->serializer, 'serializer', $instance);
        static::assertAttributeEquals($this->configHelper, 'configHelper', $instance);
        static::assertAttributeEquals($this->httpContext, 'httpContext', $instance);
        static::assertAttributeEquals($this->eventsForThirdPartyModulesMock, 'eventsForThirdPartyModules', $instance);
    }

    /**
     * @test
     * that getLayout
     *
     * @covers ::getLayout
     *
     * @throws \ReflectionException if getLayout method is not defined
     */
    public function getLayout_withVariousStates_returnsLayout()
    {
        $this->eventsForThirdPartyModulesMock->expects(static::once())->method('runFilter')
            ->with('filterMinicartAddonsLayout', [])
            ->willReturn(self::DEFAULT_LAYOUT);
        static::assertEquals(
            self::DEFAULT_LAYOUT,
            TestHelper::invokeMethod($this->minicartAddons, 'getLayout')
        );
    }

    /**
     * @test
     * that getLayout
     *
     * @covers ::getLayout
     *
     * @throws \ReflectionException if getLayout method is not defined
     */
    public function getLayout_withLayoutPropertyExist_returnsLayout()
    {
        TestHelper::setInaccessibleProperty($this->minicartAddons, '_layout', self::DEFAULT_LAYOUT);
        static::assertEquals(
            self::DEFAULT_LAYOUT,
            TestHelper::invokeMethod($this->minicartAddons, 'getLayout')
        );
    }

    /**
     * @test
     * that getLayoutJSON returns the result of the getLayout method in JSON format
     *
     * @covers ::getLayoutJSON
     */
    public function getLayoutJSON()
    {
        TestHelper::setInaccessibleProperty($this->minicartAddons, '_layout', self::DEFAULT_LAYOUT);
        $result = $this->minicartAddons->getLayoutJSON();
        static::assertJson($result);
        static::assertEquals(self::DEFAULT_LAYOUT, $this->serializer->unserialize($result));
    }

    /**
     * @test
     * that shouldShow returns true only if Bolt on minicart is enabled and at least one minicart addon is enabled
     *
     * @dataProvider shouldShow_withVariousStatesProvider
     *
     * @covers ::shouldShow
     *
     * @param bool  $minicartSupport configuration value
     * @param array $layout stubbed result of the getLayout method call
     * @param bool  $expectedResult of the method call
     *
     * @throws \ReflectionException if configHelper property is undefined
     */
    public function shouldShow_withVariousStates_determinesIfAddonsShouldBeRendered(
        $minicartSupport,
        $layout,
        $expectedResult
    ) {
        $store = $this->objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
        $storeId = $store->getStore()->getId();
        $configData = [
            [
                'path' => Config::XML_PATH_MINICART_SUPPORT,
                'value' => $minicartSupport,
                'scope' => \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                'scopeId' => $storeId
            ]
        ];
        TestUtils::setupBoltConfig($configData);

        TestHelper::setInaccessibleProperty($this->minicartAddons, '_layout', $layout);
        static::assertEquals($expectedResult, $this->minicartAddons->shouldShow());
    }

    /**
     * Data provider for {@see shouldShow_withVariousStates_determinesIfAddonsShouldBeRendered}
     *
     * @return array[] containing minicart support config value, subbed result of getLayout and expected result
     */
    public function shouldShow_withVariousStatesProvider()
    {
        return [
            [
                'minicartSupport' => true,
                'layout'          => self::DEFAULT_LAYOUT,
                'expectedResult'  => true,
            ],
            [
                'minicartSupport' => true,
                'layout'          => [],
                'expectedResult'  => false,
            ],
            [
                'minicartSupport' => false,
                'layout'          => self::DEFAULT_LAYOUT,
                'expectedResult'  => false,
            ],
        ];
    }
}
