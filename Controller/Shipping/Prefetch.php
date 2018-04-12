<?php
/**
 *
 * Copyright © 2013-2017 Bolt, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Bolt\Boltpay\Controller\Shipping;

use Exception;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Magento\Framework\HTTP\ZendClientFactory;
use Bolt\Boltpay\Model\Api\ShippingMethods;
use Bolt\Boltpay\Helper\Cart as CartHelper;
use Bolt\Boltpay\Helper\Bugsnag;
use Bolt\Boltpay\Helper\Config as ConfigHelper;


/**
 * Class Prefetch.
 * Gets user location data from geolocation API.
 * Calls shipping estimation with the location data.
 * Shipping is prefetched and cached.
 *
 * @package Bolt\Boltpay\Controller\Shipping
 */
class Prefetch extends Action
{
	/** @var CheckoutSession */
	protected $checkoutSession;

	/**
	 * @var ZendClientFactory
	 */
	protected $httpClientFactory;

	/**
	 * @var ShippingMethods
	 */
	protected $shippingMethods;

	/**
	 * @var CartHelper
	 */
	protected $cartHelper;

	/**
	 * @var Bugsnag
	 */
	protected $bugsnag;

	/**
	 * @var ConfigHelper
	 */
	protected $configHelper;


	// GeoLocation API endpoint
	private $locationURL = "http://freegeoip.net/json/%s";


	/**
	 * @param Context $context
	 * @param CheckoutSession $checkoutSession
	 * @param ZendClientFactory $httpClientFactory
	 * @param ShippingMethods $shippingMethods
	 * @param CartHelper $cartHelper
	 * @param Bugsnag $bugsnag
	 * @param ConfigHelper $configHelper
	 *
	 * @codeCoverageIgnore
	 */
	public function __construct(
		Context           $context,
		CheckoutSession   $checkoutSession,
		ZendClientFactory $httpClientFactory,
		ShippingMethods   $shippingMethods,
		CartHelper        $cartHelper,
		Bugsnag           $bugsnag,
		configHelper     $configHelper
	) {
		parent::__construct($context);
		$this->checkoutSession   = $checkoutSession;
		$this->httpClientFactory = $httpClientFactory;
		$this->shippingMethods   = $shippingMethods;
		$this->cartHelper        = $cartHelper;
		$this->bugsnag           = $bugsnag;
		$this->configHelper      = $configHelper;
	}


	/**
	 * Gets the IP address of the requesting customer.  This is used instead of simply $_SERVER['REMOTE_ADDR'] to give more accurate IPs if a
	 * proxy is being used.
	 *
	 * @return string  The IP address of the customer
	 */
	private function getIpAddress(){
		foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key){
			if ($ips = $this->getRequest()->getServer($key, false)){
				foreach (explode(',', $ips) as $ip){
					$ip = trim($ip); // just to be safe
					if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false){
						return $ip;
					}
				}
			}
		}
	}


	/**
	 * @return void
	 * @throws Exception
	 */
	public function execute()
	{
		try {

			if (!$this->configHelper->getPrefetchShipping()) return;

			$quote = $this->checkoutSession->getQuote();
			if (!$quote->getId()) return;

			$ip = $this->getIpAddress();

			$client = $this->httpClientFactory->create();
			$client->setUri(sprintf($this->locationURL, $ip));
			$client->setConfig(['maxredirects' => 0, 'timeout' => 30]);

			$response     = $client->request();
			$responseBody = $response->getBody();

			$location = json_decode($responseBody);

			$shipping_address = [
				'country_code' => $location->country_code,
				'postal_code'  => $location->zip_code,
				'region'       => $location->region_name,
				'locality'     => $location->city,
			];

			$cart = $this->cartHelper->getCartData(null, null);

			///////////////////////////////////////////////////////////////////////////
			// Estimate shipping for geolocated address
			///////////////////////////////////////////////////////////////////////////
			$this->shippingMethods->shippingEstimation($quote, $cart['cart'], $shipping_address);
			///////////////////////////////////////////////////////////////////////////


			/////////////////////////////////////////////////////////////////////////////////
			// Quote address may have already been set - checkout page or logged in customer.
			// Run the estimation with quote address unchanged
			/////////////////////////////////////////////////////////////////////////////////
			$shippingAddress = $quote->getShippingAddress();
			$shipping_address = [
				'country_code' => $shippingAddress->getData('country_id') ?: '',
				'postal_code'  => $shippingAddress->getData('postcode') ?: '',
				'region'       => $shippingAddress->getData('region') ?: '',
				'locality'     => $shippingAddress->getData('city') ?: '',
			];
			$this->shippingMethods->shippingEstimation($quote, $cart['cart'], $shipping_address);
			/////////////////////////////////////////////////////////////////////////////////

		} catch ( Exception $e ) {
			$this->bugsnag->notifyException($e);
			throw $e;
		}
	}
}
