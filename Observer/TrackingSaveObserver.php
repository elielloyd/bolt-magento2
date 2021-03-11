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

namespace Bolt\Boltpay\Observer;

use Bolt\Boltpay\Helper\Api as ApiHelper;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Bolt\Boltpay\Helper\Config as ConfigHelper;
use Magento\Framework\DataObjectFactory;
use Bolt\Boltpay\Helper\Bugsnag;
use Bolt\Boltpay\Helper\MetricsClient;
use Bolt\Boltpay\Helper\FeatureSwitch\Decider;
use Magento\Framework\Exception\LocalizedException;
use Exception;

class TrackingSaveObserver implements ObserverInterface
{
    /**
     * @var ConfigHelper
     */
    private $configHelper;

    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactory;

    /**
     * @var ApiHelper
     */
    private $apiHelper;

    /**
     * @var Bugsnag
     */
    private $bugsnag;

    /**
     * @var MetricsClient
     */
    private $metricsClient;

    /**
     * @var Decider
     */
    private $decider;

    /**
     * @param ConfigHelper      $configHelper
     * @param DataObjectFactory $dataObjectFactory
     * @param ApiHelper $apiHelper
     * @param Bugsnag $bugsnag
     * @param MetricsClient $metricsClient
     * @param Decider $decider
     *
     */
    public function __construct(
        ConfigHelper $configHelper,
        DataObjectFactory $dataObjectFactory,
        ApiHelper $apiHelper,
        Bugsnag $bugsnag,
        MetricsClient $metricsClient,
        Decider $decider
    ) {
        $this->configHelper = $configHelper;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->apiHelper = $apiHelper;
        $this->bugsnag = $bugsnag;
        $this->metricsClient = $metricsClient;
        $this->decider = $decider;
    }

    /**
     * Convert item options into bolt format
     * @param array item options
     * @return array
     */
    private function getPropertiesByProductOptions($itemOptions)
    {
        if (!isset($itemOptions['attributes_info'])) {
            return [];
        }
        $properties = [];
        foreach ($itemOptions['attributes_info'] as $attributeInfo) {
            // Convert attribute to string if it's a boolean before sending to the Bolt API
            $attributeValue = is_bool($attributeInfo['value']) ?
                var_export($attributeInfo['value'], true) : $attributeInfo['value'];
            $attributeLabel = $attributeInfo['label'];
            $properties[] = (object) [
                'name' => $attributeLabel,
                'value' => $attributeValue
            ];
        }
        return $properties;
    }

    /**
     * @param $attribute
     * @return mixed|null
     */
    private function flatten($attribute) {
        if (is_object($attribute)) {
            $flattend = get_object_vars($attribute);
            if (count($flattend) == 1) {
                return $flattend[0];
            } else {
                return null;
            }
        }
        return $attribute;
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        if (!$this->decider->isTrackShipmentEnabled()) {
            return;
        }

        try {
            $startTime = $this->metricsClient->getCurrentTime();
            $tracking = $observer->getEvent()->getTrack();

            // If we update track (don't create) and carrier and number are the same do nothing
            if (!$this->isTrackNew($tracking)) {
                $this->metricsClient->processMetric(
                    "tracking_creation.success",
                    1,
                    "tracking_creation.latency",
                    $startTime
                );
                return;
            }

            $shipment = $tracking->getShipment();
            $order = $shipment->getOrder();
            $payment = $order->getPayment();

            $isNonBoltOrder = !$payment || $payment->getMethod() != \Bolt\Boltpay\Model\Payment::METHOD_CODE;
            if ($isNonBoltOrder) {
                $transactionReference = $order->getBoltTransactionReference();
            } else {
                $transactionReference = $payment->getAdditionalInformation('transaction_reference');
            }

            if ($transactionReference === null) {
                $quoteId = $order->getQuoteId();
                $this->bugsnag->notifyError("Missing transaction reference", "QuoteID: {$quoteId}");
                $this->metricsClient->processMetric(
                    "tracking_creation.failure",
                    1,
                    "tracking_creation.latency",
                    $startTime
                );
                return;
            }

            $items = [];
            foreach ($shipment->getItemsCollection() as $item) {
                $orderItem = $item->getOrderItem();
                if ($orderItem->getParentItem()) {
                    continue;
                }

                $items[] = (object)[
                    'reference' => $orderItem->getProductId(),
                    'options'   => $this->getPropertiesByProductOptions($orderItem->getProductOptions()),
                ];
            }

            $apiKey = $this->configHelper->getApiKey($order->getStoreId());

            $trackNumber = $this->flatten($tracking->getTrackNumber());
            if ($trackNumber === null) {
                $this->bugsnag->notifyError("Ill formatted track number",
                    \sprintf("track number: %s", var_export($tracking->getTrackNumber(), true)));
                $this->metricsClient->processMetric(
                    "tracking_creation.failure",
                    1,
                    "tracking_creation.latency",
                    $startTime
                );
                return;
            }

            $carrierCode = $this->flatten($tracking->getCarrierCode());
            if ($carrierCode === null) {
                $this->bugsnag->notifyError("Ill formatted carrier code",
                    \sprintf("carrier code: %s", var_export($tracking->getCarrierCode(), true)));
                $this->metricsClient->processMetric(
                    "tracking_creation.failure",
                    1,
                    "tracking_creation.latency",
                    $startTime
                );
                return;
            }

            $trackingData = [
                'transaction_reference' => $transactionReference,
                'tracking_number'       => $trackNumber,
                'carrier'               => $carrierCode,
                'items'                 => $items,
                'is_non_bolt_order'     => $isNonBoltOrder,
                'tracking_entity_id'    => $tracking->getId(),
            ];

            //Request Data
            $requestData = $this->dataObjectFactory->create();
            $requestData->setApiData($trackingData);
            $requestData->setDynamicApiUrl(ApiHelper::API_CREATE_TRACKING);
            $requestData->setApiKey($apiKey);

            //Build Request
            $request = $this->apiHelper->buildRequest($requestData);
            $result = $this->apiHelper->sendRequest($request);

            if ($result != 200) {
                $this->metricsClient->processMetric(
                    "tracking_creation.failure",
                    1,
                    "tracking_creation.latency",
                    $startTime
                );
                return;
            }
            $this->metricsClient->processMetric(
                "tracking_creation.success",
                1,
                "tracking_creation.latency",
                $startTime
            );
        } catch (Exception $e) {
            $this->bugsnag->notifyException($e);
            $this->metricsClient->processMetric(
                "tracking_creation.failure",
                1,
                "tracking_creation.latency",
                $startTime
            );
        } catch (LocalizedException $e) {
            $this->bugsnag->notifyException($e);
            $this->metricsClient->processMetric(
                "tracking_creation.failure",
                1,
                "tracking_creation.latency",
                $startTime
            );
        }
    }

    /**
     * @param Track $track
     *
     * @return bool
     */
    private function isTrackNew($track)
    {
        $version = $this->configHelper->getStoreVersion();
        // we can not know if track new or not for magento < 2.3.1
        if (version_compare($version, '2.3.1', '<')) {
            return true;
        }
        $origData = $track->getOrigData();
        if (
            $origData &&
            $origData['track_number'] == $track->getTrackNumber() &&
            $origData['carrier_code'] == $track->getCarrierCode()
        ) {
            return false;
        }
        return true;
    }
}
