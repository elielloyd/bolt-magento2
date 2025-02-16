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
namespace Bolt\Boltpay\Plugin\Magento\Inventory\Model\SourceItem\Command;

use Bolt\Boltpay\Helper\FeatureSwitch\Decider;
use Bolt\Boltpay\Model\CatalogIngestion\ProductEventProcessor;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Catalog ingestion product event processor after source items update
 */
class SourceItemSavePlugin
{
    /**
     * @var ProductEventProcessor
     */
    private $productEventProcessor;

    /**
     * @var Decider
     */
    private $featureSwitches;

    /**
     * @var array
     */
    private $beforeProductStatuses;

    /**
     * @param ProductEventProcessor $productEventProcessor
     * @param Decider $featureSwitches
     */
    public function __construct(
        ProductEventProcessor $productEventProcessor,
        Decider $featureSwitches
    ) {
        $this->productEventProcessor = $productEventProcessor;
        $this->featureSwitches = $featureSwitches;
    }

    /**
     * Save product salable statuses before source items save
     *
     * @param SourceItemsSaveInterface $subject
     * @param array $sourceItems
     * @return array[]
     * @throws LocalizedException
     */
    public function beforeExecute(
        SourceItemsSaveInterface $subject,
        array $sourceItems
    ): array
    {
        if (!$this->featureSwitches->isCatalogIngestionEnabled() || empty($sourceItems)) {
            return [$sourceItems];
        }
        $this->beforeProductStatuses = $this->productEventProcessor->getProductStatusesSourceItemsBased($sourceItems);
        return [$sourceItems];
    }

    /**
     * Process catalog ingestion product event after source items save
     *
     * @param SourceItemsSaveInterface $subject
     * @param $result
     * @param array $sourceItems
     * @return void
     * @throws LocalizedException
     */
    public function afterExecute(
        SourceItemsSaveInterface $subject,
        $result,
        array $sourceItems
    ): void
    {
        if (!$this->featureSwitches->isCatalogIngestionEnabled() ||
            empty($sourceItems) ||
            empty($this->beforeProductStatuses)
        ) {
            return;
        }
        $afterProductStatuses = $this->productEventProcessor->getProductStatusesSourceItemsBased($sourceItems);
        $this->productEventProcessor->processProductEventSourceItemsBased(
            $this->beforeProductStatuses,
            $afterProductStatuses,
            $sourceItems
        );
    }
}
