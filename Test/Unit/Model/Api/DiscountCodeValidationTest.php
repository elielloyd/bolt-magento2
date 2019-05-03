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
 * @copyright  Copyright (c) 2018 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Test\Unit\Model\Api;

use Bolt\Boltpay\Model\Api\DiscountCodeValidation as BoltDiscountCodeValidation;

use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Exception as WebApiException;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\UsageFactory;
use Magento\Framework\DataObjectFactory;
use Magento\SalesRule\Model\Rule\CustomerFactory;
use Bolt\Boltpay\Model\ThirdPartyModuleFactory;
use Magento\SalesRule\Model\Rule\Condition\AddressFactory;

use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;

use Bolt\Boltpay\Helper\Log as LogHelper;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Bolt\Boltpay\Helper\Config as ConfigHelper;
use Bolt\Boltpay\Helper\Hook as HookHelper;
use Bolt\Boltpay\Helper\Discount as DiscountHelper;
use Bolt\Boltpay\Helper\Bugsnag;

use Magento\Quote\Model\Quote;
use Bolt\Boltpay\Model\ErrorResponse as BoltErrorResponse;
use Magento\Quote\Api\CartRepositoryInterface as QuoteRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Directory\Model\Region as RegionModel;
use Magento\Quote\Model\Quote\TotalsCollector;


/**
 * Class DiscountCodeValidationTest
 *
 * @package Bolt\Boltpay\Test\Unit\Model\Api
 */
class DiscountCodeValidationTest extends TestCase
{
    /**
     * @var BoltDiscountCodeValidation
     */
    private $currentMock;    
    
    /**
     * @var CouponFactory
     */
    private $couponFactoryMock;
    
    /**
     * @var RuleFactory
     */
    private $ruleFactoryMock;
    
    /**
     * @var UsageFactory
     */
    private $usageFactoryMock;
    
    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactoryMock;
    
    /**
     * @var ThirdPartyModuleFactory
     */
    private $moduleGiftCardAccountMock;
    
    /**
     * @var ThirdPartyModuleFactory
     */
    private $moduleUnirgyGiftCertMock;
    
    /**
     * @var ThirdPartyModuleFactory|\Unirgy\Giftcert\Helper\Data
     */
    private $moduleUnirgyGiftCertHelperMock;
    
    /**
     * @var CustomerFactory
     */
    private $customerFactoryMock;
    
    /**
     * @var AddressFactory
     */
    private $addressFactory;
    
    /**
     * @var Request
     */
    private $request;
    
    /**
     * @var Response
     */
    private $response;

    /**
     * @var HookHelper
     */
    private $hookHelper;
    
    /**
     * @var LogHelper
     */
    private $logHelper;
    
    /**
     * @var ConfigHelper
     */
    private $configHelper;
    
    /**
     * @var CartHelper
     */
    private $cartHelper;
    
    /**
     * @var DiscountHelper
     */
    private $discountHelper;
    
    /**
     * @var Bugsnag
     */
    private $bugsnag;
    
    /**
     * @var TimezoneInterface
     */
    private $timezone;
    
    /**
     * @var BoltErrorResponse
     */
    private $errorResponse;
    
    /**
     * @var QuoteRepository
     */
    private $quoteRepositoryForUnirgyGiftCert;
    
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;
    
    /**
     * @var RegionModel
     */
    private $regionModel;
    
    /**
     * @var TotalsCollector
     */
    private $totalsCollector;
    

    /**
     * @inheritdoc
     */
    public function setUp()
    {
        $this->createFactoryMocks();
        $this->createHelperMocks();
        
        $this->request = $this->getMockBuilder(Request::class)
            ->setMethods(['getContent'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->response = $this->getMockBuilder(Response::class)
            ->setMethods(['sendResponse'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->errorResponse = $this->getMockBuilder(BoltErrorResponse::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->quoteRepositoryForUnirgyGiftCert = $this->createMock(QuoteRepository::class);
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->regionModel = $this->getMockBuilder(RegionModel::class)
            ->setMethods(['loadByName'])
            ->disableOriginalConstructor()
            ->getMock();
        
        
        $this->totalsCollector = $this->getMockBuilder(TotalsCollector::class)
            ->setMethods(['collectAddressTotals'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->totalsCollector->method('collectAddressTotals')
            ->withAnyParameters()
            ->willReturnSelf();
    }

    /**
     * @test
     */
    public function validateWithSimpleCoupon()
    {
        $quoteId = 1001;
        $parentQuoteId = 1000;
        $customerId = null;
        $couponCode = 'FIXED20';
        $request_data = (object) ( array(
            'discount_code' => $couponCode,
            'cart'  =>
                (object) ( array(
                    'order_reference' => $quoteId,
                    'display_id'      => '100050001 / '.$quoteId
                ) )
        ) );

        $this->request->method('getContent')
            ->willReturn(json_encode($request_data));
        
        $this->couponFactoryMock->method('isObjectNew')
            ->willReturn(false);
        $this->couponFactoryMock->method('getCouponId')
            ->willReturn(3);
        $this->couponFactoryMock->method('getId')
            ->willReturn(3);
        $this->couponFactoryMock->method('getRuleId')
            ->willReturn(6);
        $this->couponFactoryMock->method('getUsageLimit')
            ->willReturn(100);
        $this->couponFactoryMock->method('getTimesUsed')
            ->willReturn(null);
        
        $this->moduleUnirgyGiftCertMock->method('getInstance')
            ->willReturn(null);
        
        $ruleFactoryMock = $this->getMockBuilder(RuleFactory::class)
            ->setMethods(['create', 'load', 'isObjectNew', 'getId', 'getToDate', 'getFromDate', 'getDescription', 'getSimpleAction'])
            ->disableOriginalConstructor()
            ->getMock();
        $ruleFactoryMock->method('create')
            ->willReturnSelf();
        $ruleFactoryMock->method('load')
            ->withAnyParameters()
            ->willReturnSelf();
        $ruleFactoryMock->method('isObjectNew')
            ->willReturn(false);
        $ruleFactoryMock->method('getId')
            ->willReturn(6);            
        $ruleFactoryMock->method('getDescription')
            ->willReturn('Simple discount code');
        $ruleFactoryMock->method('getSimpleAction')
            ->willReturn('cart_fixed');      
            
        $shippingAddress = $this->getMockBuilder(\Magento\Quote\Model\Quote\Address::class)
            ->setMethods(['addData', 'setCollectShippingRates', 'setShippingMethod', 'getGroupedAllShippingRates',
                'getShippingDiscountAmount', 'getShippingAmount', 'save'
            ])
            ->disableOriginalConstructor()
            ->getMock();
        $shippingAddress->method('setShippingMethod')
            ->withAnyParameters()
            ->willReturnSelf();
        $shippingAddress->method('save')
            ->willReturnSelf();
        $shippingAddress->method('setCollectShippingRates')
            ->with(true)
            ->willReturnSelf();
        $shippingAddress->method('getShippingDiscountAmount')
            ->willReturn('0');
        $shippingAddress->method('getShippingAmount')
            ->willReturn('5');

        $addressRate = $this->getMockBuilder(\Magento\Quote\Model\Quote\Address\Rate::class)
            ->disableOriginalConstructor()
            ->getMock();

        $shippingRates =[['flatrate' => $addressRate]];
        $shippingAddress->method('getGroupedAllShippingRates')
            ->willReturn($shippingRates);
            
        $this->cartHelper->method('getActiveQuoteById')
            ->will(
                $this->returnCallback(function ($arg) use ($couponCode, $shippingAddress, $customerId, $quoteId, $parentQuoteId){
                    return $this->getQuoteMock($couponCode, $shippingAddress, $customerId, $quoteId, $parentQuoteId);
                })
            );
        $this->cartHelper->method('getQuoteById')
            ->will(
                $this->returnCallback(function ($arg) use ($couponCode, $shippingAddress, $customerId, $quoteId, $parentQuoteId){
                    return $this->getQuoteMock($couponCode, $shippingAddress, $customerId, $quoteId, $parentQuoteId);
                })
            );
        $this->cartHelper->method('getRoundAmount')
            ->will($this->returnCallback(function ($arg1) {
                return round($arg1 * 100);
            }));
            
        $currentMock = new BoltDiscountCodeValidation(
            $this->request,
            $this->response,
            $this->couponFactoryMock,
            $this->moduleGiftCardAccountMock,
            $this->moduleUnirgyGiftCertMock,
            $this->moduleUnirgyGiftCertHelperMock,
            $this->quoteRepositoryForUnirgyGiftCert,
            $this->checkoutSession,
            $ruleFactoryMock,
            $this->logHelper,
            $this->errorResponse,
            $this->usageFactoryMock,
            $this->dataObjectFactoryMock,
            $this->timezone,
            $this->customerFactoryMock,
            $this->bugsnag,
            $this->cartHelper,
            $this->configHelper,
            $this->hookHelper,
            $this->discountHelper,
            $this->regionModel,
            $this->totalsCollector
        );  
        $result = $currentMock->validate();

        // If another exception happens, the test will fail.
        $this->assertTrue($result);
    }
    
    /**
     * @test
     */
    public function validateWithShippingOnlyCoupon()
    {
        $quoteId = 1001;
        $parentQuoteId = 1000;
        $customerId = null;
        $couponCode = 'FREESHIPPINGFIXED';

        $request_shipping_addr = (object) array(
                                        'company' => "",
                                        'country' => "United States",
                                        'country_code' => "US",
                                        'email' => "integration@bolt.com",
                                        'first_name' => "YevhenBolt",
                                        'last_name' => "BoltTest2",
                                        'locality' => "New York",
                                        'phone' => "+1 231 231 1234",
                                        'postal_code' => "10001",
                                        'region' => "New York",
                                        'street_address1' => "228 5th Avenue",
                                        'street_address2' => "",
                                        'email_address'   => 'test@bolt.com',
                                    );
        $request_data = (object) ( array(
            'discount_code' => $couponCode,
            'cart'  =>
                (object) ( array(
                    'order_reference' => $quoteId,
                    'display_id'      => '100050001 / '.$quoteId,
                    'shipments'       =>
                        array(
                            0 =>
                                (object) ( array(
                                    'shipping_address' => $request_shipping_addr,
                                    'shipping_method'  => 'unknown',
                                    'service'          => 'Flat Rate - Fixed',
                                    'cost'             =>
                                        (object) ( array(
                                            'amount'          => 500,
                                            'currency'        => 'USD',
                                            'currency_symbol' => '$',
                                        ) ),
                                    'tax_amount'       =>
                                        (object) ( array(
                                            'amount'          => 0,
                                            'currency'        => 'USD',
                                            'currency_symbol' => '$',
                                        ) ),
                                    'reference'        => 'flatrate_flatrate',
                                ) ),
                        ),
                ) )
        ) );

        $ruleFactoryMock = $this->getMockBuilder(RuleFactory::class)
            ->setMethods(['create', 'load', 'isObjectNew', 'getId', 'getToDate',
                          'getFromDate', 'getDescription', 'getSimpleAction',
                          'getConditions'])
            ->disableOriginalConstructor()
            ->getMock();
        $ruleFactoryMock->method('create')
            ->willReturnSelf();
        $ruleFactoryMock->method('load')
            ->withAnyParameters()
            ->willReturnSelf();
        $ruleFactoryMock->method('isObjectNew')
            ->willReturn(false);
        $ruleFactoryMock->method('getId')
            ->willReturn(6);            
        $ruleFactoryMock->method('getDescription')
            ->willReturn('freeshipping for fixed price shipping option');
        $ruleFactoryMock->method('getSimpleAction')
            ->willReturn('cart_fixed');
    
        
        // Add Shipping Method Condition
        $shippingCondMock = $this->getMockBuilder(AddressFactory::class)
            ->setMethods(['getType', 'getAttribute', 'getOperator', 'getValue'])
            ->disableOriginalConstructor()
            ->getMock();
        $shippingCondMock->method('getType')
            ->willReturn('Magento\SalesRule\Model\Rule\Condition\Address');
        $shippingCondMock->method('getAttribute')
            ->willReturn('shipping_method');
        $shippingCondMock->method('getOperator')
            ->willReturn('==');
        $shippingCondMock->method('getValue')
            ->willReturn('flatrate_flatrate');

        $ruleFactoryMock->method('getConditions')
            ->willReturn(array($shippingCondMock));

        $this->request->method('getContent')
            ->willReturn(json_encode($request_data));
        
        $this->couponFactoryMock->method('isObjectNew')
            ->willReturn(false);
        $this->couponFactoryMock->method('getCouponId')
            ->willReturn(3);
        $this->couponFactoryMock->method('getId')
            ->willReturn(3);
        $this->couponFactoryMock->method('getRuleId')
            ->willReturn(6);
        $this->couponFactoryMock->method('getUsageLimit')
            ->willReturn(100);
        $this->couponFactoryMock->method('getTimesUsed')
            ->willReturn(null);
        
        $this->moduleUnirgyGiftCertMock->method('getInstance')
            ->willReturn(null);    
            
        $shippingAddress = $this->getMockBuilder(\Magento\Quote\Model\Quote\Address::class)
            ->setMethods(['addData', 'setCollectShippingRates', 'setShippingMethod', 'getGroupedAllShippingRates',
                'getShippingDiscountAmount', 'getShippingAmount', 'save'
            ])
            ->disableOriginalConstructor()
            ->getMock();
        $shippingAddress->method('setShippingMethod')
            ->withAnyParameters()
            ->willReturnSelf();
        $shippingAddress->method('save')
            ->willReturnSelf();
        $shippingAddress->method('setCollectShippingRates')
            ->with(true)
            ->willReturnSelf();
        $shippingAddress->method('getShippingDiscountAmount')
            ->willReturn('0');
        $shippingAddress->method('getShippingAmount')
            ->willReturn('5');

        $addressRate = $this->getMockBuilder(\Magento\Quote\Model\Quote\Address\Rate::class)
            ->disableOriginalConstructor()
            ->getMock();

        $shippingRates =[['flatrate' => $addressRate]];
        $shippingAddress->method('getGroupedAllShippingRates')
            ->willReturn($shippingRates);
            
        $this->cartHelper->method('getActiveQuoteById')
            ->will(
                $this->returnCallback(function ($arg) use ($couponCode, $shippingAddress, $customerId, $quoteId, $parentQuoteId){
                    return $this->getQuoteMock($couponCode, $shippingAddress, $customerId, $quoteId, $parentQuoteId);
                })
            );
        $this->cartHelper->method('getQuoteById')
            ->will(
                $this->returnCallback(function ($arg) use ($couponCode, $shippingAddress, $customerId, $quoteId, $parentQuoteId){
                    return $this->getQuoteMock($couponCode, $shippingAddress, $customerId, $quoteId, $parentQuoteId);
                })
            );
        $this->cartHelper->method('getRoundAmount')
            ->will($this->returnCallback(function ($arg1) {
                return round($arg1 * 100);
            }));
        $this->cartHelper->method('handleSpecialAddressCases')
            ->willReturn($request_shipping_addr);
        $this->cartHelper->method('validateEmail')
            ->willReturn(true);
            
        $currentMock = new BoltDiscountCodeValidation(
            $this->request,
            $this->response,
            $this->couponFactoryMock,
            $this->moduleGiftCardAccountMock,
            $this->moduleUnirgyGiftCertMock,
            $this->moduleUnirgyGiftCertHelperMock,
            $this->quoteRepositoryForUnirgyGiftCert,
            $this->checkoutSession,
            $ruleFactoryMock,
            $this->logHelper,
            $this->errorResponse,
            $this->usageFactoryMock,
            $this->dataObjectFactoryMock,
            $this->timezone,
            $this->customerFactoryMock,
            $this->bugsnag,
            $this->cartHelper,
            $this->configHelper,
            $this->hookHelper,
            $this->discountHelper,
            $this->regionModel,
            $this->totalsCollector
        );  
        $result = $currentMock->validate();

        // If another exception happens, the test will fail.
        $this->assertTrue($result);
    }


    /**
     * Get quote mock with quote items
     *
     * @param $customerId
     * @param $quoteId
     * @param $parentQuoteId
     * @param $shippingAddress
     * @oaram $couponCode
     * @return \PHPUnit\Framework\MockObject\MockObject
     * @throws \ReflectionException
     */
    private function getQuoteMock($couponCode, $shippingAddress, $customerId = null, $quoteId = 1001, $parentQuoteId = 1000)
    {
        $quoteItem = $this->getMockBuilder(\Magento\Quote\Model\Quote\Item::class)
            ->setMethods(['getSku', 'getQty', 'getCalculationPrice'])
            ->disableOriginalConstructor()
            ->getMock();
        $quoteItem->method('getSku')
            ->willReturn('TestProduct');
        $quoteItem->method('getQty')
            ->willReturn(1);

        $quoteMethods = [
            'getId', 'getBoltParentQuoteId', 'getSubtotal', 'getAllVisibleItems',
            'getAppliedRuleIds', 'isVirtual', 'getShippingAddress', 'collectTotals',
            'getQuoteCurrencyCode', 'getItemsCount', 'getCustomerId', 'setCouponCode',
            'getCouponCode'
        ];
        $quote = $this->getMockBuilder(Quote::class)
            ->setMethods($quoteMethods)
            ->disableOriginalConstructor()
            ->getMock();

        $quote->method('getId')
            ->willReturn($quoteId);
        $quote->method('getBoltParentQuoteId')
            ->willReturn($quoteId);
        $quote->method('getSubtotal')
            ->willReturn(100);
        $quote->method('getAllVisibleItems')
            ->willReturn([$quoteItem]);
        $quote->method('getAppliedRuleIds')
            ->willReturn('2,3');
        $quote->method('isVirtual')
            ->willReturn(false);
        $quote->method('getShippingAddress')
            ->willReturn($shippingAddress);
        $quote->method('getQuoteCurrencyCode')
            ->willReturn('$');
        $quote->method('collectTotals')
            ->willReturnSelf();
        $quote->method('getItemsCount')
            ->willReturn(1);
        $quote->method('getCustomerId')
            ->willReturn($customerId);
        $quote->method('setCouponCode')
            ->willReturnSelf();
        $quote->method('getCouponCode')
            ->willReturn($couponCode);

        return $quote;
    }

    private function createFactoryMocks()
    {
        $this->couponFactoryMock = $this->getMockBuilder(CouponFactory::class)
            ->setMethods(['create', 'loadByCode', 'isObjectNew', 'getCouponId', 'getId', 'getRuleId', 'getUsageLimit', 'getTimesUsed'])
            //->disableOriginalConstructor()
            ->getMock();
        $this->couponFactoryMock->method('create')
            ->willReturnSelf();
        $this->couponFactoryMock->method('loadByCode')
            ->withAnyParameters()
            ->willReturnSelf();         
            
        $this->usageFactoryMock = $this->getMockBuilder(UsageFactory::class)
            ->setMethods(['create', 'loadByCustomerCoupon'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->usageFactoryMock->method('create')
            ->willReturnSelf();
        $this->usageFactoryMock->method('loadByCustomerCoupon')
            ->withAnyParameters()
            ->willReturnSelf();
            
        $this->dataObjectFactoryMock = $this->getMockBuilder(DataObjectFactory::class)
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->dataObjectFactoryMock->method('create')
            ->willReturnSelf();
            
        $this->moduleGiftCardAccountMock = $this->getMockBuilder(ThirdPartyModuleFactory::class)
            ->setMethods(['getInstance'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->moduleGiftCardAccountMock->method('getInstance')
            ->willReturnSelf();
        
        $this->moduleUnirgyGiftCertMock = $this->getMockBuilder(ThirdPartyModuleFactory::class)
            ->setMethods(['getInstance'])
            ->disableOriginalConstructor()
            ->getMock();
        
        $this->moduleUnirgyGiftCertHelperMock = $this->getMockBuilder(ThirdPartyModuleFactory::class)
            ->setMethods(['getInstance'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->moduleUnirgyGiftCertHelperMock->method('getInstance')
            ->willReturnSelf();
        
        $this->customerFactoryMock = $this->getMockBuilder(CustomerFactory::class)
            ->setMethods(['create', 'loadByCustomerRule'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerFactoryMock->method('create')
            ->willReturnSelf();
        $this->customerFactoryMock->method('loadByCustomerRule')
            ->withAnyParameters()
            ->willReturnSelf();
    }
    
    private function createHelperMocks()
    {
        $this->hookHelper = $this->createMock(HookHelper::class);
        
        $this->logHelper = $this->createMock(LogHelper::class);
            
        $this->cartHelper = $this->getMockBuilder(CartHelper::class)
            ->setMethods(['getOrderByIncrementId', 'getQuoteById', 'getActiveQuoteById', 'handleSpecialAddressCases', 'validateEmail', 'getRoundAmount', 'getCartData'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->cartHelper->method('getOrderByIncrementId')
            ->willReturn(false);
            
        $this->configHelper = $this->getMockBuilder(ConfigHelper::class)
            ->setMethods(['getIgnoredShippingAddressCoupons'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->configHelper->method('getIgnoredShippingAddressCoupons')
            ->willReturn([]);
            
        $this->discountHelper = $this->getMockBuilder(DiscountHelper::class)
            ->setMethods(['loadAmastyGiftCard', 'removeAmastyGiftCard', 'applyAmastyGiftCard', 'cloneAmastyGiftCards'])
            ->disableOriginalConstructor()
            ->getMock();
            
        $this->bugsnag = $this->getMockBuilder(Bugsnag::class)
            ->setMethods(['notifyException'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->bugsnag->method('notifyException')
            ->will($this->returnArgument(0));
    }
}
