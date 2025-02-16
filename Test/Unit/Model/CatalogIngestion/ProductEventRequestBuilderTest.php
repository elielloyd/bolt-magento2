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

namespace Bolt\Boltpay\Test\Unit\Model\CatalogIngestion;

use Bolt\Boltpay\Api\ProductEventRepositoryInterface;
use Bolt\Boltpay\Helper\Config as BoltConfig;
use Bolt\Boltpay\Helper\FeatureSwitch\Decider;
use Bolt\Boltpay\Model\CatalogIngestion\ProductEventProcessor;
use Bolt\Boltpay\Test\Unit\BoltTestCase;
use Bolt\Boltpay\Test\Unit\TestHelper;
use Bolt\Boltpay\Test\Unit\TestUtils;
use Bolt\Boltpay\Model\CatalogIngestion\ProductEventRequestBuilder;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Config\Model\ResourceModel\Config as ResourceConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Module\Manager;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Vertex\Tax\Model\ModuleManager;

/**
 * Class ProductEventRequestBuilderTest
 * @coversDefaultClass \Bolt\Boltpay\Model\CatalogIngestion\ProductEventRequestBuilder
 */
class ProductEventRequestBuilderTest extends BoltTestCase
{
    private const PRODUCT_SKU = 'ci_simple';

    private const PRODUCT_NAME = 'Catalog Ingestion Simple Product';

    private const PRODUCT_PRICE = 100;

    private const PRODUCT_QTY = 100;

    private const API_KEY = '3c2d5104e7f9d99b66e1c9c550f6566677bf81de0d6f25e121fdb57e47c2eafc';

    private const PUBLISH_KEY = 'ifssM6pxV64H.FXY3JhSL7w9f.c243fecf459ed259019ea58d7a30307edf2f65442c305f086105b2f66fe6c006';

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var ProductEventRequestBuilder
     */
    private $productEventRequestBuilder;

    /**
     * @var ProductEventRepositoryInterface
     */
    private $productEventRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var Manager
     */
    private $moduleManger;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @inheritDoc
     */
    protected function setUpInternal()
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->productEventRequestBuilder =$this->objectManager->get(ProductEventRequestBuilder::class);
        $this->resource = $this->objectManager->get(ResourceConnection::class);
        $this->moduleManger = $this->objectManager->get(ModuleManager::class);
        $this->storeManager = $this->objectManager->get(StoreManagerInterface::class);
        $this->productEventRepository = $this->objectManager->get(ProductEventRepositoryInterface::class);
        $productEventProcessor = $this->objectManager->get(ProductEventProcessor::class);
        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);
        $featureSwitches = $this->createMock(Decider::class);
        $featureSwitches->method('isCatalogIngestionEnabled')->willReturn(true);
        TestHelper::setProperty($productEventProcessor, 'featureSwitches', $featureSwitches);
        $websiteId = $this->storeManager->getWebsite()->getId();
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        $apikey = $encryptor->encrypt(self::API_KEY);
        $publishKey = $encryptor->encrypt(self::PUBLISH_KEY);
        $configData = [
            [
                'path' => BoltConfig::XML_PATH_CATALOG_INGESTION_ENABLED,
                'value' => 1,
                'scope' => ScopeInterface::SCOPE_WEBSITES,
                'scopeId' => $websiteId,
            ],
            [
                'path'    => BoltConfig::XML_PATH_PUBLISHABLE_KEY_CHECKOUT,
                'value'   => $publishKey,
                'scope'   => ScopeInterface::SCOPE_STORES,
                'scopeId' => $websiteId,
            ],
            [
                'path'    => BoltConfig::XML_PATH_API_KEY,
                'value'   => $apikey,
                'scope'   => ScopeInterface::SCOPE_STORES,
                'scopeId' => $websiteId,
            ]
        ];
        TestUtils::setupBoltConfig($configData);
    }

    /**
     * @inheritdoc
     */
    protected function tearDownInternal(): void
    {
        $this->cleanDataBase();
        parent::tearDownInternal();
    }

    /**
     * @test
     */
    public function testGetRequest()
    {
        $product = $this->createProduct();
        $productEvent = $this->productEventRepository->getByProductId($product->getId());
        $request = $this->productEventRequestBuilder->getRequest($productEvent, $this->storeManager->getWebsite()->getId());
        $apiData = $request->getApiData();
        $fulfillmentCenterID = ($this->moduleManger->isEnabled('Magento_InventoryCatalog')) ?
            'Default Stock' : 'default';
        $expectedApiData = [
            'operation' => $productEvent->getType(),
            'timestamp' => strtotime($productEvent->getCreatedAt()),
            'product' => [
                'product' =>
                    [
                        'MerchantProductID' => $product->getId(),
                        'ProductType' => 'simple',
                        'SKU' => 'ci_simple',
                        'URL' => $product->getProductUrl(),
                        'Name' => 'Catalog Ingestion Simple Product',
                        'ManageInventory' => true,
                        'Visibility' => 'visible',
                        'Backorder' => 'no',
                        'Availability' => 'in_stock',
                        'ShippingRequired' => true,
                        'Prices' => [
                            [
                                'ListPrice' => 10000,
                                'SalePrice' => 10000,
                                'Currency' => 'USD',
                                'Locale' => 'en_US',
                                'Unit' => ''
                            ]
                        ],
                        'Inventories' => [
                            [
                                'FulfillmentCenterID' => $fulfillmentCenterID,
                                'InventoryLevel' => self::PRODUCT_QTY
                            ]
                        ],
                        'Media' => [],
                        'Options' => [],
                        'Properties' => [
                            [
                                'Name' => 'cost',
                                'NameID' => 81,
                                'Value' => NULL,
                                'ValueID' => NULL,
                                'DisplayType' => 'price',
                                'DisplayName' => 'cost',
                                'DisplayValue' => NULL,
                                'Visibility' => 'visible',
                                'TextLabel' => 'Cost',
                                'ImageURL' => NULL,
                                'Position' => 0
                            ]
                        ],
                        'Description' => 'Product Description'
                    ],
                'variants' => []
            ]
        ];
        $this->assertEquals($apiData, $expectedApiData);
    }

    /**
     * @test
     */
    public function testGetRequest_WithoutApiKeys()
    {
        $this->expectExceptionMessage('Bolt API Key or Publishable Key - Multi Step is not configured');
        $websiteId = $this->storeManager->getWebsite()->getId();
        $configData = [
            [
                'path'    => BoltConfig::XML_PATH_PUBLISHABLE_KEY_CHECKOUT,
                'value'   => '',
                'scope'   => ScopeInterface::SCOPE_STORES,
                'scopeId' => $websiteId,
            ]
        ];
        TestUtils::setupBoltConfig($configData);

        $product = $this->createProduct();
        $productEvent = $this->productEventRepository->getByProductId($product->getId());
        $this->productEventRequestBuilder->getRequest($productEvent, $this->storeManager->getWebsite()->getId());
    }

    /**
     * Cleaning test data from database
     *
     * @return void
     */
    private function cleanDataBase(): void
    {
        $websiteId = $this->storeManager->getWebsite()->getId();
        $configResource = $this->objectManager->get(ResourceConfig::class);
        $configResource->deleteConfig(BoltConfig::XML_PATH_CATALOG_INGESTION_ENABLED, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        $configResource->deleteConfig(BoltConfig::XML_PATH_PUBLISHABLE_KEY_CHECKOUT, ScopeInterface::SCOPE_STORES, $websiteId);
        $configResource->deleteConfig(BoltConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORES, $websiteId);
        $connection = $this->resource->getConnection('default');
        $connection->truncateTable($this->resource->getTableName('bolt_product_event'));
        $connection->delete($connection->getTableName('catalog_product_entity'));
    }

    /**
     * Create simple product
     *
     * @return ProductInterface
     */
    private function createProduct(): ProductInterface
    {
        $product = Bootstrap::getObjectManager()->create(Product::class);
        $product->setTypeId(ProductType::TYPE_SIMPLE)
            ->setAttributeSetId(4)
            ->setName(self::PRODUCT_NAME)
            ->setSku(self::PRODUCT_SKU)
            ->setPrice(self::PRODUCT_PRICE)
            ->setDescription('Product Description')
            ->setMetaTitle('meta title')
            ->setMetaKeyword('meta keyword')
            ->setMetaDescription('meta description')
            ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
            ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            ->setCategoryIds([2])
            ->setStoreId($this->storeManager->getStore()->getId())
            ->setWebsiteIds([$this->storeManager->getStore()->getWebsiteId()])
            ->setStockData(
                [
                    'qty' => self::PRODUCT_QTY,
                    'is_in_stock' => 1,
                    'manage_stock' => 1,
                ]
            )
            ->setTaxClassId(0)
            ->setCanSaveCustomOptions(true)
            ->setHasOptions(true)
            ->setUrlKey('test-simple-product-'.round(microtime(true) * 1000));
        return $this->productRepository->save($product);
    }
}
