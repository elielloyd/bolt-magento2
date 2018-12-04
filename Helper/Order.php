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

namespace Bolt\Boltpay\Helper;

use Bolt\Boltpay\Helper\Api as ApiHelper;
use Bolt\Boltpay\Helper\Config as ConfigHelper;
use Bolt\Boltpay\Model\Payment;
use Bolt\Boltpay\Model\Response;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Directory\Model\Region as RegionModel;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractExtensibleModel;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order as OrderModel;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder as TransactionBuilder;
use Bolt\Boltpay\Model\Service\InvoiceService;
use Magento\Sales\Api\Data\InvoiceInterface;
use Zend_Http_Client_Exception;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\App\State;
use Bolt\Boltpay\Helper\Log as LogHelper;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Bolt\Boltpay\Helper\Session as SessionHelper;
use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * Class Order
 * Boltpay Order helper
 *
 * @package Bolt\Boltpay\Helper
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Order extends AbstractHelper
{
    // Bolt transaction states
    const TS_PENDING               = 'cc_payment:pending';
    const TS_AUTHORIZED            = 'cc_payment:authorized';
    const TS_CAPTURED              = 'cc_payment:captured';
    const TS_COMPLETED             = 'cc_payment:completed';
    const TS_CANCELED              = 'cc_payment:cancelled';
    const TS_REJECTED_REVERSIBLE   = 'cc_payment:rejected_reversible';
    const TS_REJECTED_IRREVERSIBLE = 'cc_payment:rejected_irreversible';
    const TS_ZERO_AMOUNT           = 'zero_amount:completed';
    const TS_CREDIT_COMPLETED      = 'cc_credit:completed';

    // Posible transation state transitions
    private $validStateTransitions = [
        null => [
            self::TS_ZERO_AMOUNT,
            self::TS_PENDING,
            // for historic data (order placed before plugin update) does not have "previous state"
            self::TS_CREDIT_COMPLETED
        ],
        self::TS_PENDING => [
            self::TS_AUTHORIZED,
            self::TS_COMPLETED,
            self::TS_CANCELED,
            self::TS_REJECTED_REVERSIBLE,
            self::TS_REJECTED_IRREVERSIBLE,
            self::TS_ZERO_AMOUNT
        ],
        self::TS_AUTHORIZED => [
            self::TS_CAPTURED,
            self::TS_CANCELED,
            self::TS_COMPLETED
        ],
        self::TS_CAPTURED => [
            self::TS_CAPTURED,
            self::TS_CANCELED,
            self::TS_COMPLETED,
            self::TS_CREDIT_COMPLETED
        ],
        self::TS_CANCELED => [],
        self::TS_COMPLETED => [
            self::TS_COMPLETED,
            self::TS_CREDIT_COMPLETED
        ],
        self::TS_ZERO_AMOUNT => [],
        self::TS_REJECTED_REVERSIBLE => [
            self::TS_AUTHORIZED,
            self::TS_COMPLETED,
            self::TS_REJECTED_IRREVERSIBLE,
            self::TS_CANCELED
        ],
        self::TS_REJECTED_IRREVERSIBLE => [],
        self::TS_CREDIT_COMPLETED => [
            self::TS_CREDIT_COMPLETED,
            self::TS_COMPLETED,
            self::TS_CAPTURED,
            self::TS_CANCELED
        ]
    ];

    /** @var State */
    private $appState;

    /**
     * @var ApiHelper
     */
    private $apiHelper;

    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * @var RegionModel
     */
    private $regionModel;

    /**
     * @var QuoteManagement
     */
    private $quoteManagement;

    /**
     * @var OrderSender
     */
    private $emailSender;

    /**
     * @var InvoiceService
     */
    private $invoiceService;

    /**
     * @var InvoiceSender
     */
    private $invoiceSender;

    /**
     * @var TransactionBuilder
     */
    private $transactionBuilder;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactory;

    /**
     * @var LogHelper
     */
    private $logHelper;

    /**
     * @var Bugsnag
     */
    private $bugsnag;

    /**
     * @var CartHelper
     */
    private $cartHelper;

    /**
     * @var \Magento\Payment\Model\Info
     */
    private $quotePaymentInfoInstance = null;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /** @var SessionHelper */
    private $sessionHelper;

    /**
     * @param Context $context
     * @param ApiHelper $apiHelper
     * @param Config $configHelper
     * @param RegionModel $regionModel
     * @param QuoteManagement $quoteManagement
     * @param OrderSender $emailSender
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param TransactionBuilder $transactionBuilder
     * @param TimezoneInterface $timezone
     * @param DataObjectFactory $dataObjectFactory
     * @param State $appState
     * @param LogHelper $logHelper
     * @param Bugsnag $bugsnag
     * @param CartHelper $cartHelper
     * @param ResourceConnection $resourceConnection
     * @param SessionHelper $sessionHelper
     *
     * @codeCoverageIgnore
     */
    public function __construct(
        Context $context,
        ApiHelper $apiHelper,
        ConfigHelper $configHelper,
        RegionModel $regionModel,
        QuoteManagement $quoteManagement,
        OrderSender $emailSender,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        TransactionBuilder $transactionBuilder,
        TimezoneInterface $timezone,
        DataObjectFactory $dataObjectFactory,
        State $appState,
        LogHelper $logHelper,
        Bugsnag $bugsnag,
        CartHelper $cartHelper,
        ResourceConnection $resourceConnection,
        SessionHelper $sessionHelper
    ) {
        parent::__construct($context);
        $this->apiHelper = $apiHelper;
        $this->configHelper = $configHelper;
        $this->regionModel = $regionModel;
        $this->quoteManagement = $quoteManagement;
        $this->emailSender = $emailSender;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->transactionBuilder = $transactionBuilder;
        $this->timezone = $timezone;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->appState = $appState;
        $this->logHelper = $logHelper;
        $this->bugsnag = $bugsnag;
        $this->cartHelper = $cartHelper;
        $this->resourceConnection = $resourceConnection;
        $this->sessionHelper = $sessionHelper;
    }

    /**
     * Fetch transaction details info
     *
     * @api
     *
     * @param string $reference
     *
     * @return Response
     * @throws LocalizedException
     * @throws Zend_Http_Client_Exception
     */
    public function fetchTransactionInfo($reference)
    {

        //Request Data
        $requestData = $this->dataObjectFactory->create();
        $requestData->setDynamicApiUrl(ApiHelper::API_FETCH_TRANSACTION . "/" . $reference);
        $requestData->setApiKey($this->configHelper->getApiKey());
        $requestData->setRequestMethod('GET');
        //Build Request
        $request = $this->apiHelper->buildRequest($requestData);

        $result = $this->apiHelper->sendRequest($request);
        $response = $result->getResponse();

        return $response;
    }

    /**
     * Set quote shipping method from transaction data
     *
     * @param Quote $quote
     * @param $transaction
     *
     * @throws \Exception
     */
    private function setShippingMethod($quote, $transaction)
    {
        if ($quote->isVirtual()) {
            return;
        }

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true);

        $shippingMethod = $transaction->order->cart->shipments[0]->reference;

        $shippingAddress->setShippingMethod($shippingMethod)->save();
    }

    /**
     * Set Quote address data helper method.
     *
     * @param Address $quoteAddress
     * @param $address
     *
     * @throws \Exception
     */
    private function setAddress($quoteAddress, $address)
    {
        $address = $this->cartHelper->handleSpecialAddressCases($address);

        $region = $this->regionModel->loadByName(@$address->region, @$address->country_code);

        $address_data = [
            'firstname'    => @$address->first_name,
            'lastname'     => @$address->last_name,
            'street'       => trim(@$address->street_address1 . "\n" . @$address->street_address2),
            'city'         => @$address->locality,
            'country_id'   => @$address->country_code,
            'region'       => @$address->region,
            'postcode'     => @$address->postal_code,
            'telephone'    => @$address->phone_number,
            'region_id'    => $region ? $region->getId() : null,
            'company'      => @$address->company,
        ];

        if ($this->cartHelper->validateEmail(@$address->email_address)) {
            $address_data['email'] = $address->email_address;
        }

        $quoteAddress->setShouldIgnoreValidation(true);
        $quoteAddress->addData($address_data)->save();
    }

    /**
     * Set Quote shipping address data.
     *
     * @param Quote $quote
     * @param $transaction
     *
     * @return void
     * @throws \Exception
     */
    private function setShippingAddress($quote, $transaction)
    {
        $address = @$transaction->order->cart->shipments[0]->shipping_address;
        if ($address) {
            $this->setAddress($quote->getShippingAddress(), $address);
        }
    }

    /**
     * Set Quote billing address data.
     *
     * @param Quote $quote
     * @param $transaction
     *
     * @throws \Exception
     */
    private function setBillingAddress($quote, $transaction)
    {
        $address = @$transaction->order->cart->billing_address;
        if ($address) {
            $this->setAddress($quote->getBillingAddress(), $address);
        }
    }

    /**
     * For guest checkout set customer email in quote
     *
     * @param Quote $quote
     * @param string $guestEmail
     *
     * @return void
     */
    private function addCustomerDetails($quote, $guestEmail)
    {
        if (!$quote->getCustomerId()) {
            $quote->setCustomerId(null);
            $quote->setCheckoutMethod('guest');
            $quote->setCustomerEmail($guestEmail);
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
        }
    }

    /**
     * Set Quote payment method, 'boltpay'
     *
     * @param Quote $quote
     *
     * @throws LocalizedException
     * @throws \Exception
     */
    private function setPaymentMethod($quote)
    {
        $quote->setPaymentMethod(Payment::METHOD_CODE);
        // Set Sales Order Payment
        $quote->getPayment()->importData(['method' => Payment::METHOD_CODE])->save();
    }

    /**
     * Transform Quote to Order and send email to the customer.
     *
     * @param Quote $quote
     * @param \stdClass $transaction
     *
     * @param string|null $boltTraceId
     * @return AbstractExtensibleModel|OrderInterface|null|object
     * @throws LocalizedException
     * @throws \Exception
     */
    private function createOrder($quote, $transaction, $boltTraceId = null)
    {
        // Load logged in customer checkout and customer sessions from cached session id.
        // Replace parent quote with immutable quote in checkout session.
        $this->sessionHelper->loadSession($quote);

        $this->setShippingAddress($quote, $transaction);
        $this->setBillingAddress($quote, $transaction);
        $this->setShippingMethod($quote, $transaction);

        $email = @$transaction->order->cart->billing_address->email_address ?:
            @$transaction->order->cart->shipments[0]->shipping_address->email_address;

        $this->addCustomerDetails($quote, $email);

        $this->setPaymentMethod($quote);

        $this->cartHelper->saveQuote($quote);

        // assign credit card info to the payment info instance
        $this->setQuotePaymentInfoData(
            $quote,
            [
                'cc_last_4' => @$transaction->from_credit_card->last4,
                'cc_type' => @$transaction->from_credit_card->network
            ]
        );

        // check if the order has been created in the meanwhile
        /** @var OrderModel $order */
        $order = $this->cartHelper->getOrderByIncrementId($quote->getReservedOrderId());

        if ($order && $order->getId()) {
            throw new LocalizedException(__(
                'Duplicate Order Creation Attempt. Order #: %1',
                $quote->getReservedOrderId()
            ));
        }

        $order = $this->quoteManagement->submit($quote);

        if (Hook::$fromBolt) {
            $order->addStatusHistoryComment(
                "BOLTPAY INFO :: THIS ORDER WAS CREATED VIA WEBHOOK<br>Bolt traceId: $boltTraceId"
            );
            $order->save();
            // Send order confirmation email to customer.
            // Emulate frontend area in order for email
            // template to be loaded from the correct path
            // even if run from the hook.
            if (!$order->getEmailSent()) {
                $this->appState->emulateAreaCode('frontend', function () use ($order) {
                    $this->emailSender->send($order);
                });
            }
        } else {
            if (!$order->getEmailSent()) {
                // Send order confirmation email to customer.
                $this->emailSender->send($order);
            }
        }

        return $order;
    }

    /**
     * Assign data to the quote payment info instance
     *
     * @param Quote $quote
     * @param array $data
     * @return void
     */
    private function setQuotePaymentInfoData($quote, $data)
    {
        foreach ($data as $key => $value) {
            $this->getQuotePaymentInfoInstance($quote)->setData($key, $value);
        }
    }

    /**
     * Returns quote payment info object
     *
     * @param Quote $quote
     * @return \Magento\Payment\Model\Info
     */
    private function getQuotePaymentInfoInstance($quote)
    {
        return $this->quotePaymentInfoInstance ?:
            $this->quotePaymentInfoInstance = $quote->getPayment()->getMethodInstance()->getInfoInstance();
    }

    /**
     * Delete redundant clones and parent quote.
     *
     * @param Quote $quote
     */
    private function deleteRedundantQuotes($quote)
    {

        $connection = $this->resourceConnection->getConnection();

        // get table name with prefix
        $tableName = $this->resourceConnection->getTableName('quote');

        $sql = "DELETE FROM {$tableName} WHERE bolt_parent_quote_id = :bolt_parent_quote_id AND entity_id != :entity_id";
        $bind = [
            'bolt_parent_quote_id' => $quote->getBoltParentQuoteId(),
            'entity_id' => $quote->getId()
        ];

        $connection->query($sql, $bind);
    }

    /**
     * Set parent quote as inactive.
     *
     * @param Quote $quote
     */
    public function deactivateParentQuote($quote)
    {
        try {
            $parentQuote = $this->cartHelper->getQuoteById($quote->getBoltParentQuoteId());
            $parentQuote->setIsActive(false);
        } catch (NoSuchEntityException $e) {
            $this->bugsnag->registerCallback(function ($report) use ($quote) {
                $report->setMetaData([
                    'QUOTE' => [
                        'quoteId' => $quote->getId(),
                        'parentId' => $quote->getBoltParentQuoteId(),
                        'orderId' => $quote->getReservedOrderId()
                    ]
                ]);
            });
        }
    }

    /**
     * Save/create the order (checkout, orphaned transaction),
     * Update order payment / transaction data (checkout, web hooks)
     *
     * @param string $reference     Bolt transaction reference
     *
     * @return mixed
     * @throws \Exception
     * @throws LocalizedException
     * @throws Zend_Http_Client_Exception
     */
    public function saveUpdateOrder($reference, $boltTraceId = null)
    {
        $transaction = $this->fetchTransactionInfo($reference);

        ///////////////////////////////////////////////////////////////
        // Get order id and immutable quote id stored with transaction.
        // Take into account orders created with data in old format
        // where only reserved_order_id was stored in display_id field
        // and the immutable quote_id in order_reference
        ///////////////////////////////////////////////////////////////
        list($incrementId, $quoteId) = array_pad(
            explode(' / ', $transaction->order->cart->display_id),
            2,
            null
        );
        if (!$quoteId) {
            $quoteId = $transaction->order->cart->order_reference;
        }
        ///////////////////////////////////////////////////////////////

        ///////////////////////////////////////////////////////////////
        // try loading (immutable) quote from entity id. if called from
        // hook the quote might have been cleared, resulting in error.
        // prevent failure and log event to bugsnag.
        ///////////////////////////////////////////////////////////////
        try {
            $quote = $this->cartHelper->getQuoteById($quoteId);
        } catch (NoSuchEntityException $e) {
            $this->bugsnag->registerCallback(function ($report) use ($incrementId, $quoteId) {
                $report->setMetaData([
                    'ORDER' => [
                        'incrementId' => $incrementId,
                        'quoteId' => $quoteId,
                    ]
                ]);
            });
            $quote = null;
        }
        ///////////////////////////////////////////////////////////////

        // check if the order exists
        $order = $this->cartHelper->getOrderByIncrementId($incrementId);

        // if not create the order
        if (!$order || !$order->getId()) {
            if (!$quote || !$quote->getId()) {
                throw new LocalizedException(__('Unknown quote id: %1', $quoteId));
            }

            $order = $this->createOrder($quote, $transaction, $boltTraceId);

            // Add the user_note to the order comments and make it visible for customer.
            if (isset($transaction->order->user_note)) {
                $this->setOrderUserNote($order, $transaction->order->user_note);
            }
        }

        if ($quote) {
            // Set parent quote as inactive
            $this->deactivateParentQuote($quote);

            // Delete redundant clones and parent quote
            $this->deleteRedundantQuotes($quote);
        }

        if (Hook::$fromBolt) {
            // if called from hook update order payment transactions
            $this->updateOrderPayment($order, $transaction);
        } else {
            // if called from the store controller return quote and order
            // wait for the hook call to update the payment
            return [$quote, $order];
        }
    }

    /**
     * Add user note as status history comment
     *
     * @param OrderModel $order
     * @param string     $userNote
     *
     * @return OrderModel
     */
    public function setOrderUserNote($order, $userNote)
    {

        $order
            ->addStatusHistoryComment($userNote)
            ->setIsVisibleOnFront(true)
            ->setIsCustomerNotified(false);

        return $order;
    }

    /**
     * Creates a link to the transaction page on the Bolt merchant dasboard
     * to be saved with the order payment info message.
     *
     * @param string $reference
     * @return string
     */
    public function formatReferenceUrl($reference)
    {
        $url = $this->configHelper->getMerchantDashboardUrl().'/transaction/'.$reference;
        return '<a target="_blank" href="'.$url.'">'.$reference.'</a>';
    }

    /**
     * Format the (class internal) unambiguous transaction state out of the type, status and previously recordered status.
     * Infer eventually missing states. Represent Bolt partial capture AUTHORIZED state as CAPTURED.
     *
     * @param \stdClass $transaction
     * @param OrderPaymentInterface $payment
     * @return string
     */
    public function getTransactionState($transaction, $payment)
    {
        $transactionState = $transaction->type.":".$transaction->status;
        $prevTransactionState = $payment->getAdditionalInformation('transaction_state');
        $prevCaptures = $payment->getAdditionalInformation('captures') ?: [];

        // No previous state recorded.
        // Unless the state is TS_ZERO_AMOUNT (valid start transaction state, as well as TS_PENDING)
        // or TS_CREDIT_COMPLETED (for historical reasons, old orders refund,
        // legacy code when order was created, no state recorded) put it in TS_PENDING state.
        // It can corelate with the $transactionState or not in case the hook is late due connection problems and
        // the status has changed in the meanwhile.
        if (!$prevTransactionState) {
            if (in_array($transactionState, [self::TS_ZERO_AMOUNT, self::TS_CREDIT_COMPLETED])) {
                return $transactionState;
            }
            return self::TS_PENDING;
        }

        // The previously recorded state is either TS_PENDING or TS_REJECTED_REVERSIBLE. Authorization occured but also
        // some of the funds were captured before the TS_AUTHORIZED state is recorded in Magento. Mark the transaction
        // as TS_AUTHORIZED and wait for the next hook to record the CAPTURE.
        if (in_array($prevTransactionState, [self::TS_PENDING, self::TS_REJECTED_REVERSIBLE]) &&
            $transactionState == self::TS_AUTHORIZED &&
            $transaction->captures
        ) {
            return self::TS_AUTHORIZED;
        }

        // The previously recorded state is either TS_PENDING or TS_REJECTED_REVERSIBLE and the transaction state is
        // TS_COMPLETED. If there is more than one capture in $transaction->captures array then the TS_AUTHORIZED state
        // is missing. Set the state to TS_AUTHORIZED and process captures on next hook requests.
        if (in_array($prevTransactionState, [self::TS_PENDING, self::TS_REJECTED_REVERSIBLE]) &&
            $transactionState == self::TS_COMPLETED &&
            count($transaction->captures) > 1
        ) {
            return self::TS_AUTHORIZED;
        }

        // The transaction was in TS_AUTHORIZED state, now it's TS_COMPLETED but not all partial captures are
        // processed. Mark it TS_CAPTURED.
        if ($prevTransactionState == self::TS_AUTHORIZED &&
            $transactionState == self::TS_COMPLETED &&
            count($transaction->captures) - count($prevCaptures) > 1
        ) {
            return self::TS_CAPTURED;
        }

        // The transaction was TS_AUTHORIZED, now it has captures, put it in TS_CAPTURED state.
        if ($transactionState == self::TS_AUTHORIZED &&
            $transaction->captures
        ) {
            return self::TS_CAPTURED;
        }

        // Previous partial capture was partially or fully refunded. Transaction is still TS_AUTHORIZED on Bolt side.
        // Set it to TS_CAPTURED.
        if ($prevTransactionState == self::TS_CREDIT_COMPLETED &&
            $transactionState == self::TS_AUTHORIZED
        ) {
            return self::TS_CAPTURED;
        }

        // return transaction state as it is in fetched transaction info. No need to change it.
        return $transactionState;
    }

    /**
     * Check if the transition from one transaction state to another is valid.
     *
     * @param string|null $prevTransactionState
     * @param string $newTransactionState
     * @return bool
     */
    private function validateTransition($prevTransactionState, $newTransactionState)
    {
        return in_array($newTransactionState, $this->validStateTransitions[$prevTransactionState]);
    }

    /**
     * Record total amount mismatch between magento and bolt order.
     * Log the error in order comments and report via bugsnag.
     * Put the order ON HOLD if it's a mismatch.
     *
     * @param OrderModel $order
     * @param \stdClass $transaction
     * @return bool true if the order was placed on hold, otherwise false
     */
    private function holdOnTotalsMismatch($order, $transaction)
    {
        $boltTotal = $transaction->order->cart->total_amount->amount;
        $storeTotal = round($order->getGrandTotal() * 100);

        // Stop if no mismatch
        if ($boltTotal == $storeTotal) {
            return false;
        }

        // Put the order on hold
        $order->setStatus(OrderModel::STATE_HOLDED);
        $order->setState(OrderModel::STATE_HOLDED);

        // Add order status history comment
        $comment = __(
            'BOLTPAY INFO :: THERE IS A MISMATCH IN THE ORDER PAID AND ORDER RECORDED.<br>
             Paid amount: %1 Recorded amount: %2<br>Bolt transaction: %3',
            $boltTotal / 100,
            $order->getGrandTotal(),
            $this->formatReferenceUrl($transaction->reference)
        );
        $order->addStatusHistoryComment($comment);

        // Get the quote id
        list($incrementId, $quoteId) = array_pad(
            explode(' / ', $transaction->order->cart->display_id),
            2,
            null
        );
        if (!$quoteId) {
            $quoteId = $transaction->order->cart->order_reference;
        }

        // If the quote exists collect cart data for bugsnag
        try {
            $quote = $this->cartHelper->getQuoteById($quoteId);
            $cart = $this->cartHelper->getCartData(true, false, $quote);
        } catch (NoSuchEntityException $e) {
            // Quote was cleaned by cron job
            $cart = ['The quote does not exist.'];
        }

        // Log the debug info
        $this->bugsnag->registerCallback(function ($report) use (
            $transaction,
            $cart,
            $incrementId,
            $boltTotal,
            $storeTotal
        ) {
            $report->setMetaData([
                'TOTALS_MISMATCH' => [
                    'Reference' => $transaction->reference,
                    'Order ID' => $incrementId,
                    'Bolt Total' => $boltTotal,
                    'Store Total' => $storeTotal,
                    'Bolt Cart' => $transaction->order->cart,
                    'Store Cart' => $cart
                ]
            ]);
        });

        $order->save();

        return true;
    }

    /**
     * Get (informal) transaction status to be stored with status history comment
     *
     * @param string $transactionState
     * @return string
     */
    private function getBoltTransactionStatus($transactionState)
    {
        return [
            self::TS_ZERO_AMOUNT => 'ZERO AMOUNT COMPLETED',
            self::TS_PENDING => 'UNDER REVIEW',
            self::TS_AUTHORIZED => 'AUTHORIZED',
            self::TS_CAPTURED => 'CAPTURED',
            self::TS_COMPLETED => 'COMPLETED',
            self::TS_CANCELED => 'CANCELED',
            self::TS_REJECTED_REVERSIBLE => 'REVERSIBLE REJECTED',
            self::TS_REJECTED_IRREVERSIBLE => 'IRREVERSIBLE REJECTED',
            self::TS_CREDIT_COMPLETED => Hook::$fromBolt ? 'REFUNDED UNSYNCHRONISED' : 'REFUNDED'
        ][$transactionState];
    }

    /**
     * Generate data to be stored with the transaction
     *
     * @param OrderModel $order
     * @param \stdClass $transaction
     * @param null|int $amount
     */
    private function formatTransactionData($order, $transaction, $amount)
    {
        return [
            'Time' => $this->timezone->formatDateTime(
                date('Y-m-d H:i:s', $transaction->date / 1000),
                2,
                2
            ),
            'Reference' => $transaction->reference,
            'Amount' => $order->getBaseCurrency()->formatTxt($amount / 100),
            'Transaction ID' => $transaction->id
        ];
    }

    /**
     * Return the first unprocessed capture from the captures array (or null)
     *
     * @param OrderPaymentInterface $payment
     * @param \stdClass $transaction
     * @return mixed
     */
    private function getUnprocessedCapture($payment, $transaction)
    {
        $prevCaptureIds = $payment->getAdditionalInformation('captures') ?: [];
        return @end(array_filter(
            $transaction->captures,
            function($capture) use ($prevCaptureIds) {
                return !in_array($capture->id, $prevCaptureIds) && $capture->status == 'succeeded';
            })
        );
    }

    /**
     * Update order payment / transaction data
     *
     * @param OrderModel $order
     * @param null|\stdClass $transaction
     * @param null|string $reference
     *
     * @throws \Exception
     * @throws LocalizedException
     * @throws Zend_Http_Client_Exception
     */
    public function updateOrderPayment($order, $transaction = null, $reference = null)
    {
        // Fetch transaction info if transaction is not passed as a parameter
        if ($reference && !$transaction) {
            $transaction = $this->fetchTransactionInfo($reference);
        } else {
            $reference = $transaction->reference;
        }

        if ($order->getState() == OrderModel::STATE_HOLDED) {
            throw new LocalizedException(__(
                'Order is in ON HOLD state.'
            ));
        }

        // Check for total amount mismatch between magento and bolt order.
        if ($this->holdOnTotalsMismatch($order, $transaction)) {
            throw new LocalizedException(__(
                'Order Totals Mismatch'
            ));
        }

        /** @var OrderPaymentInterface $payment */
        $payment = $order->getPayment();

        // Get the last stored transaction parameters
        $prevTransactionState = $payment->getAdditionalInformation('transaction_state');
        $prevTransactionReference = $payment->getAdditionalInformation('transaction_reference');


        // Get the transaction state
        $transactionState = $this->getTransactionState($transaction, $payment);

        $newCapture = $this->getUnprocessedCapture($payment, $transaction);

        // Skip if there is no state change (i.e. fetch transaction call from admin panel / Payment model)
        // Reference check and $newCapture were added to support multiple refunds and captures,
        // valid same state transitions
        if (
            $transactionState == $prevTransactionState &&
            $reference == $prevTransactionReference &&
            !$newCapture
        ) {
            return;
        }

        if (!$this->validateTransition($prevTransactionState, $transactionState)) {
            throw new LocalizedException(__(
                'Invalid transaction state transition: %1 -> %2',
                $prevTransactionState,
                $transactionState
            ));
        }

        // preset default payment / transaction values
        // before more specific changes below
        if ($newCapture) {
            $amount = $newCapture->amount->amount;
        } else {
            $amount = $transaction->amount->amount;
        }
        $transactionId = $transaction->id;

        $realTransactionId = $parentTransactionId = $payment->getAdditionalInformation('real_transaction_id');
        $realTransactionId = $realTransactionId ?: $transaction->id;
        $paymentAuthorized = (bool)$payment->getAdditionalInformation('authorized');

        switch ($transactionState) {

            case self::TS_ZERO_AMOUNT:
                $orderState = OrderModel::STATE_PROCESSING;
                $transactionType = Transaction::TYPE_ORDER;
                break;

            case self::TS_PENDING:
                $orderState = OrderModel::STATE_PAYMENT_REVIEW;
                $transactionType = Transaction::TYPE_ORDER;
                break;

            case self::TS_AUTHORIZED:
                $orderState = OrderModel::STATE_PROCESSING;
                $transactionType = Transaction::TYPE_AUTH;
                $transactionId = $transaction->id.'-auth';
                break;

            case self::TS_CAPTURED:
                 if (!$newCapture) return;
                 $orderState = OrderModel::STATE_PROCESSING;
                 $transactionType = Transaction::TYPE_CAPTURE;
                 $transactionId = $transaction->id.'-capture-'.$newCapture->id;
                 $parentTransactionId = $transaction->id.'-auth';
                break;

            case self::TS_COMPLETED:
                if (!$newCapture) return;
                $orderState = OrderModel::STATE_PROCESSING;
                $transactionType = Transaction::TYPE_CAPTURE;
                if ($paymentAuthorized) {
                    $transactionId = $transaction->id.'-capture-'.$newCapture->id;
                    $parentTransactionId = $transaction->id.'-auth';
                } else {
                    $transactionId = $transaction->id.'-payment';
                }
                break;

            case self::TS_CANCELED:
                $orderState = OrderModel::STATE_CANCELED;
                $transactionType = Transaction::TYPE_VOID;
                $transactionId = $transaction->id.'-void';
                $parentTransactionId = $paymentAuthorized ? $transaction->id.'-auth' : $transaction->id;
                break;

            case self::TS_REJECTED_REVERSIBLE:
                $orderState = OrderModel::STATE_HOLDED;
                $transactionType = Transaction::TYPE_ORDER;
                $transactionId = $transaction->id.'-rejected_reversible';
                break;

            case self::TS_REJECTED_IRREVERSIBLE:
                $orderState = OrderModel::STATE_CANCELED;
                $transactionType = Transaction::TYPE_ORDER;
                $transactionId = $transaction->id.'-rejected_irreversible';
                break;

            case self::TS_CREDIT_COMPLETED:
                if (in_array($transaction->id, (array)$payment->getAdditionalInformation('refunds'))) return;
                $transactionType = Transaction::TYPE_REFUND;
                $transactionId = $transaction->id.'-refund';
                if (Hook::$fromBolt) {
                    // Refunds need to be initiated from the store admin (Invoice -> Credit Memo)
                    // If called from Bolt merchant dashboard there is no enough info to sync the totals
                    $orderState = OrderModel::STATE_HOLDED;
                } else {
                    $orderState = OrderModel::STATE_PROCESSING;
                }
                break;

            default:
                throw new LocalizedException(__(
                    'Unhandled transaction state : %1',
                    $transactionState
                ));
                break;
        }

        // set order status
        $order->setStatus($orderState);
        $order->setState($orderState);

        // format the last transaction data for storing within the order payment record instance
        $captures = $payment->getAdditionalInformation('captures') ?: [];

        if ($newCapture) {
            array_push($captures, $newCapture->id);
        }

        $refunds = $payment->getAdditionalInformation('refunds') ?: [];
        if ($transactionState == self::TS_CREDIT_COMPLETED) {
            array_push($refunds, $transaction->id);
        }

        $paymentData = [
            'real_transaction_id' => $realTransactionId,
            'transaction_reference' => $transaction->reference,
            'transaction_state' => $transactionState,
            'authorized' => $paymentAuthorized || in_array($transactionState, [self::TS_AUTHORIZED, self::TS_CAPTURED]),
            'captures' => $captures,
            'refunds' => $refunds
        ];

        // format the price with currency symbol
        $formattedPrice = $order->getBaseCurrency()->formatTxt($amount / 100);

        $message = __(
            'BOLTPAY INFO :: PAYMENT Status: %1 Amount: %2<br>Bolt transaction: %3',
            $this->getBoltTransactionStatus($transactionState),
            $formattedPrice,
            $this->formatReferenceUrl($transaction->reference)
        );

        $transactionData = $this->formatTransactionData($order, $transaction, $amount);

        // update order payment instance
        $payment->setParentTransactionId($parentTransactionId);
        $payment->setTransactionId($transactionId);
        $payment->setLastTransId($transactionId);
        $payment->setAdditionalInformation($paymentData);
        $payment->setIsTransactionClosed($transactionType != Transaction::TYPE_AUTH);

        if ($this->isCaptureHookRequest($newCapture)) {
            $this->validateCaptureAmount($order, $amount / 100);
            $invoice = $this->createOrderInvoice($order, $realTransactionId, $amount / 100);
        }

        if (!$order->getTotalDue()) {
            $payment->setShouldCloseParentTransaction(true);
        }

        if ($newCapture && @$invoice) {
            $this->_eventManager->dispatch(
                'sales_order_payment_capture',
                ['payment' => $payment, 'invoice' => $invoice]
            );
        }

        // build a new transaction record and assign it to the order and payment
        /** @var Transaction $payment_transaction */
        $payment_transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($transactionId)
            ->setAdditionalInformation([
                Transaction::RAW_DETAILS => $transactionData
            ])
            ->setFailSafe(true)
            ->build($transactionType);

            $payment->addTransactionCommentsToOrder(
                $payment_transaction,
                $message
            );

        $payment_transaction->save();

        // save payment and order
        $payment->save();
        $order->save();
    }

    /**
     * Create an invoice for the order.
     *
     * @param OrderModel $order
     * @param string $transactionId
     * @param float $amount
     *
     * @return bool
     * @throws \Exception
     * @throws LocalizedException
     */
    private function createOrderInvoice($order, $transactionId, $amount)
    {
        if ($order->getTotalInvoiced() + $amount == $order->getGrandTotal()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
        } else {
            $invoice = $this->invoiceService->prepareInvoiceWithoutItems($order, $amount);
        }

        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->setTransactionId($transactionId);
        $invoice->setBaseGrandTotal($amount);
        $invoice->setGrandTotal($amount);
        $invoice->register();
        $invoice->save();

        $order->addRelatedObject($invoice);

        if (!$invoice->getEmailSent()) {
            $this->invoiceSender->send($invoice);
        }

        //Add notification comment to order
        $order->addStatusHistoryComment(
            __('Invoice #%1 is created. Notification email is sent to customer.', $invoice->getId())
        )->setIsCustomerNotified(true)->save();

        return $invoice;
    }

    /**
     * Check if the hook is a capture request
     *
     * @param \stdClass $newCapture first unprocessed capture from the captures array
     *
     * @return bool
     */
    protected function isCaptureHookRequest($newCapture)
    {
        return  Hook::$fromBolt && $newCapture;
    }

    /**
     * @param OrderInterface $order
     * @param                                        $captureAmount
     *
     * @throws \Exception
     */
    protected function validateCaptureAmount(OrderInterface $order, $captureAmount) {
        $isInvalidAmount = !isset($captureAmount) || !is_numeric($captureAmount) || $captureAmount < 0;
        $isInvalidAmountRange = $order->getTotalInvoiced() + $captureAmount > $order->getGrandTotal();

        if($isInvalidAmount || $isInvalidAmountRange) {
            throw new \Exception( __('Capture amount is invalid'));
        }
    }
}
