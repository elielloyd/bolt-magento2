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

namespace Bolt\Boltpay\ThirdPartyModules\Mirasvit;

use Bolt\Boltpay\Model\Payment;
use Bolt\Boltpay\Helper\Bugsnag;
use Bolt\Boltpay\Helper\Discount;
use Bolt\Boltpay\Helper\Shared\CurrencyUtils;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Service\OrderService;
use Magento\Framework\App\State;
use Bolt\Boltpay\Helper\Session as SessionHelper;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Framework\Event\Observer;

class Credit
{
    const MIRASVIT_STORECREDIT = 'mirasvitstorecredit';
    
    /**
     * @var State
     */
    protected $appState;
    
    /**
     * @var SessionHelper
     */
    protected $sessionHelper;

    /**
     * @var Bugsnag
     */
    private $bugsnagHelper;
    
    /**
     * @var \Mirasvit\Credit\Helper\Data
     */
    protected $mirasvitStoreCreditHelper;
    
    /**
     * @var \Mirasvit\Credit\Helper\Calculation
     */
    protected $mirasvitStoreCreditCalculationService;
    
    /**
     * @var \Mirasvit\Credit\Api\Config\CalculationConfigInterface
     */
    protected $mirasvitStoreCreditCalculationConfig;

    /**
     * Credit constructor.
     * @param State                        $appState
     * @param SessionHelper                $sessionHelper
     * @param Bugsnag                      $bugsnagHelper
     */
    public function __construct(
        State         $appState,
        SessionHelper $sessionHelper,
        Bugsnag       $bugsnagHelper
    ) {
        $this->appState        = $appState;
        $this->sessionHelper   = $sessionHelper;
        $this->bugsnagHelper   = $bugsnagHelper;
    }

    /**
     * @param $result
     * @param $mirasvitStoreCreditHelper
     * @param $mirasvitStoreCreditCalculationService
     * @param $mirasvitStoreCreditCalculationConfig
     * @param $quote
     * @param $parentQuote
     * @param $paymentOnly
     * @return array
     */
    public function collectDiscounts(
        $result,
        $mirasvitStoreCreditHelper,
        $mirasvitStoreCreditCalculationService,
        $mirasvitStoreCreditCalculationConfig,
        $quote,
        $parentQuote,
        $paymentOnly
    ) {
        list ($discounts, $totalAmount, $diff) = $result;
        $this->mirasvitStoreCreditHelper = $mirasvitStoreCreditHelper;
        $this->mirasvitStoreCreditCalculationService = $mirasvitStoreCreditCalculationService;
        $this->mirasvitStoreCreditCalculationConfig = $mirasvitStoreCreditCalculationConfig;

        try {
            // Check whether the Mirasvit Store Credit is allowed for quote
            if ($quote->getCreditAmountUsed() > 0 && $this->getMirasvitStoreCreditUsedAmount($quote) > 0) {
                $amount = abs((float)$this->getMirasvitStoreCreditAmount($quote, $paymentOnly));
                $currencyCode = $quote->getQuoteCurrencyCode();
                $roundedAmount = CurrencyUtils::toMinor($amount, $currencyCode);
    
                $discounts[] = [
                    'description'       => 'Store Credit',
                    'reference'         => 'Store Credit',
                    'amount'            => $roundedAmount,
                    'code'              => self::MIRASVIT_STORECREDIT,
                    'discount_category' => Discount::BOLT_DISCOUNT_CATEGORY_STORE_CREDIT,
                    // For v1/discounts.code.apply and v2/cart.update
                    'discount_type'     => Discount::BOLT_DISCOUNT_TYPE_FIXED,
                    // For v1/merchant/order
                    'type'              => Discount::BOLT_DISCOUNT_TYPE_FIXED,
                ];
    
                $diff -= CurrencyUtils::toMinorWithoutRounding($amount, $currencyCode) - $roundedAmount;
                $totalAmount -= $roundedAmount;
            }
        } catch (\Exception $e) {
            $this->bugsnagHelper->notifyException($e);
        } finally {
            return [$discounts, $totalAmount, $diff];
        }
    }
    
    /**
     * @param      $quote
     *
     * @param bool $paymentOnly
     *
     * @return float
     */
    private function getMirasvitStoreCreditAmount($quote, $paymentOnly = false)
    {
        $miravitBalanceAmount = $this->getMirasvitStoreCreditUsedAmount($quote);
            
        if (!$paymentOnly) {
            if ($this->ifMirasvitCreditIsShippingTaxIncluded($quote)) {
                return $miravitBalanceAmount;
            }
        }

        $unresolvedTotal = $quote->getGrandTotal() + $quote->getCreditAmountUsed();
        $totals = $quote->getTotals();

        $tax      = isset($totals['tax']) ? $totals['tax']->getValue() : 0;
        $shipping = isset($totals['shipping']) ? $totals['shipping']->getValue() : 0;

        $unresolvedTotal = $this->mirasvitStoreCreditCalculationService->calc($unresolvedTotal, $tax, $shipping);
        
        return min($unresolvedTotal, $miravitBalanceAmount);
    }
    
    /**
     * Get Mirasvit Store credit balance used amount.
     * This method is only called when the Mirasvit_Credit module is installed and available on the quote.
     *
     * @param $quote
     *
     * @return float
     */
    private function getMirasvitStoreCreditUsedAmount($quote)
    {
        $balance = $this->mirasvitStoreCreditHelper
                        ->getBalance($quote->getCustomerId(), $quote->getQuoteCurrencyCode());

        $amount = ((float)$quote->getManualUsedCredit() > 0) ? $quote->getManualUsedCredit() : $balance->getAmount();
        if ($quote->getQuoteCurrencyCode() !== $balance->getCurrencyCode()) {
            $amount = $this->mirasvitStoreCreditCalculationService->convertToCurrency(
                $amount,
                $balance->getCurrencyCode(),
                $quote->getQuoteCurrencyCode(),
                $quote->getStore()
            );
        }

        return $amount;
    }
    
    /**
     * @param Observer $observer
     *
     * @return bool
     */
    public function checkMirasvitCreditAdminQuoteUsed($result, Observer $observer)
    {
        try {
            $payment = $observer->getEvent()->getPayment();

            if ($payment->getMethod() == Payment::METHOD_CODE &&
                $this->appState->getAreaCode() == FrontNameResolver::AREA_CODE &&
                $payment->getQuote()->getUseCredit() == Mirasvit\Credit\Model\Config::USE_CREDIT_YES
            ) {
                return true;
            }
        } catch (\Exception $e) {
            $this->bugsnagHelper->notifyException($e);
        }

        return false;
    }
    
    /**
     * To run filter to check if the Mirasvit credit amount can be applied to shipping/tax.
     *
     * @param boolean $result
     * @param Mirasvit\Credit\Api\Config\CalculationConfigInterface|Mirasvit\Credit\Service\Config\CalculationConfig $mirasvitStoreCreditCalculationConfig
     * @param Quote|object $quote
     *
     * @return boolean
     */
    public function checkMirasvitCreditIsShippingTaxIncluded(
        $result,
        $mirasvitStoreCreditCalculationConfig,
        $quote
    ) {
        $this->mirasvitStoreCreditCalculationConfig = $mirasvitStoreCreditCalculationConfig;
        
        return $this->ifMirasvitCreditIsShippingTaxIncluded($quote);
    }
    
    /**
     * If the Mirasvit credit amount can be applied to shipping/tax.
     *
     * @param Quote|object $quote
     *
     * @return boolean
     */
    private function ifMirasvitCreditIsShippingTaxIncluded($quote)
    {
        return ($this->mirasvitStoreCreditCalculationConfig->isTaxIncluded($quote->getStore())
                || $this->mirasvitStoreCreditCalculationConfig->IsShippingIncluded($quote->getStore()));
    }
    
    /**
     * Exclude the Mirasvit credit amount from shipping discount,
     * so the Bolt can apply Mirasvit credit to shipping properly.
     *
     * @param float $result
     * @param Quote|object $quote
     * @param Address|object $shippingAddress
     *
     * @return float
     */
    public function collectShippingDiscounts(
        $result,
        $quote,
        $shippingAddress
    ) {
        $mirasvitStoreCreditShippingDiscountAmount = $this->sessionHelper->getCheckoutSession()
            ->getMirasvitStoreCreditShippingDiscountAmount(0);
        $result -= $mirasvitStoreCreditShippingDiscountAmount;
        return $result;
    }
    
    /**
     * Get Additional Javascript to invalidate BoltCart.
     *
     * @param $result
     * @return string
     */
    public function getAdditionalInvalidateBoltCartJavascript($result)
    {
        $result .= 'var credit = customerData.get("credit")();
                    if (credit.data_id >= boltCartDataID) {
                        invalidateBoltCart();
                        return;
                    }';
        
        return $result;
    }

    /**
     * Return code if the quote has Mirasvit store credits.
     *
     * @param $result
     * @param $couponCode
     * @param $quote
     *
     * @return array
     */
    public function filterVerifyAppliedStoreCredit(
        $result,
        $couponCode,
        $quote
    ) {
        if ($couponCode == self::MIRASVIT_STORECREDIT && $quote->getCreditAmountUsed() > 0) {
            $result[] = $couponCode;
        }
        
        return $result;
    }
    
    /**
     * Remove Mirasvit store credits from the quote.
     *
     * @param $mirasvitStoreCreditHelper
     * @param $mirasvitStoreCreditCalculationService
     * @param $couponCode
     * @param $quote
     * @param $websiteId
     * @param $storeId
     *
     */
    public function removeAppliedStoreCredit(
        $mirasvitStoreCreditHelper,
        $mirasvitStoreCreditCalculationService,
        $couponCode,
        $quote,
        $websiteId,
        $storeId
    ) {
        $this->mirasvitStoreCreditHelper = $mirasvitStoreCreditHelper;
        $this->mirasvitStoreCreditCalculationService = $mirasvitStoreCreditCalculationService;
        
        try {
            if ($couponCode == self::MIRASVIT_STORECREDIT
                && $quote->getCreditAmountUsed() > 0
                && $this->getMirasvitStoreCreditUsedAmount($quote) > 0) {
                $quote->setUseCredit(\Mirasvit\Credit\Model\Config::USE_CREDIT_NO)
                    ->setBaseCreditAmountUsed(0)
                    ->setCreditAmountUsed(0)
                    ->setManualUsedCredit(0)
                    ->collectTotals()
                    ->save();
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
