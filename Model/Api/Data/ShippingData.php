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

namespace Bolt\Boltpay\Model\Api\Data;

use Bolt\Boltpay\Api\Data\ShippingDataInterface;
use Bolt\Boltpay\Api\Data\ShippingOptionInterface;
use Bolt\Boltpay\Api\Data\ShipToStoreOptionInterface;

/**
 * Class ShippingData. Shipping options property of Shipping.
 *
 * @package Bolt\Boltpay\Model\Api\Data
 */
class ShippingData implements ShippingDataInterface, \JsonSerializable
{
    /**
     * @var array
     */
    private $shippingOptions = [];
    
    /**
     * @var array
     */
    private $shipToStoreOptions = [];

    /**
     * Get all available shipping options.
     *
     * @api
     * @return ShippingOptionInterface[]
     */
    public function getShippingOptions()
    {
        return $this->shippingOptions;
    }

    /**
     * Set available shipping options.
     *
     * @api
     * @param ShippingOptionInterface[]
     * @return $this
     */
    public function setShippingOptions($shippingOptions)
    {
        $this->shippingOptions = $shippingOptions;
        return $this;
    }
    
    /**
     * Get all available ship to store options.
     *
     * @api
     * @return \Bolt\Boltpay\Api\Data\ShipToStoreOptionInterface[]
     */
    public function getShipToStoreOptions()
    {
        return $this->shipToStoreOptions;
    }

    /**
     * Set available ship to store options.
     *
     * @api
     * @param \Bolt\Boltpay\Api\Data\ShipToStoreOptionInterface[]
     * @return $this
     */
    public function setShipToStoreOptions($shipToStoreOptions)
    {
        $this->shipToStoreOptions = $shipToStoreOptions;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return [
            'shipping_options' => $this->shippingOptions,
            'ship_to_store_options' => $this->shipToStoreOptions
        ];
    }
}
