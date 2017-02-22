<?php
/**
 * Tweakwise & Emico (https://www.tweakwise.com/ & https://www.emico.nl/) - All Rights Reserved
 *
 * @copyright Copyright (c) 2017-2017 Tweakwise.com B.V. (https://www.tweakwise.com)
 * @license   Proprietary and confidential, Unauthorized copying of this file, via any medium is strictly prohibited
 */

namespace Emico\TweakwiseExport\Model\Write\Products;

use Emico\TweakwiseExport\Model\Config;
use Emico\TweakwiseExport\Model\Config\Source\StockCalculation;
use Emico\TweakwiseExport\Model\Helper;
use Emico\TweakwiseExport\Model\Write\EavIterator;
use Generator;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\Indexer\Category\Product\AbstractAction as CategoryProductAbstractAction;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupType;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link;
use Magento\Store\Model\StoreManager;
use Zend_Db_Expr;
use Zend_Db_Select;

class Iterator extends EavIterator
{
    /**
     * Batch size to fetch extra product data.
     */
    const BATCH_SIZE = 1000;

    /**
     * @var ProductCollectionFactory
     */
    protected $productCollectionFactory;

    /**
     * @var StoreManager
     */
    protected $storeManager;

    /**
     * @var Visibility
     */
    protected $visibility;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * Product type factory
     *
     * @var ProductType
     */
    protected $productType;

    /**
     * @var DbContext
     */
    protected $dbContext;

    /**
     * @var Config
     */
    protected $config;

    /**
     * Iterator constructor.
     *
     * @param EavConfig $eavConfig
     * @param ProductCollectionFactory $productCollectionFactory
     * @param StoreManager $storeManager
     * @param Visibility $visibility
     * @param Helper $helper
     * @param ProductType $productType
     * @param DbContext $dbContext
     * @param Config $config
     */
    public function __construct(
        EavConfig $eavConfig,
        ProductCollectionFactory $productCollectionFactory,
        StoreManager $storeManager,
        Visibility $visibility,
        Helper $helper,
        ProductType $productType,
        DbContext $dbContext,
        Config $config
    ) {
        parent::__construct($eavConfig, Product::ENTITY, []);

        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager = $storeManager;
        $this->visibility = $visibility;
        $this->helper = $helper;
        $this->productType = $productType;
        $this->dbContext = $dbContext;
        $this->config = $config;

        $this->initializeAttributes();
    }

    /**
     * @param array $params
     * @return ProductCollection
     */
    protected function createProductCollection(array $params = [])
    {
        return $this->productCollectionFactory->create($params);
    }

    /**
     * Select all attributes who should be exported
     *
     * @return $this
     */
    protected function initializeAttributes()
    {
        // Add default attributes
        $this->selectAttribute('name');
        $this->selectAttribute('sku');
        $this->selectAttribute('url_key');
        $this->selectAttribute('status');
        $this->selectAttribute('visibility');
        $this->selectAttribute('type_id');

        // Add configured attributes
        $type = $this->eavConfig->getEntityType($this->entityCode);

        /** @var Attribute $attribute */
        foreach ($type->getAttributeCollection() as $attribute) {
            if (!$this->helper->shouldExportAttribute($attribute)) {
                continue;
            }

            $this->selectAttribute($attribute->getAttributeCode());
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        $batch = [];
        foreach (parent::getIterator() as $entity) {
            if (!$entity['status']) {
                continue;
            }

            if (!in_array($entity['visibility'], $this->visibility->getVisibleInSiteIds())) {
                continue;
            }
            $batch[$entity['entity_id']] = $entity;

            if (count($batch) == self::BATCH_SIZE) {
                // After PHP7+ we can use yield from
                foreach ($this->processBatch($batch) as $processedEntity) {
                    yield $processedEntity;
                }
            }
        }

        // After PHP7+ we can use yield from
        foreach ($this->processBatch($batch) as $processedEntity) {
            yield $processedEntity;
        }
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected function getConnection()
    {
        return $this->getResources()->getConnection();
    }

    /**
     * @return ResourceConnection
     */
    protected function getResources()
    {
        return $this->dbContext->getResources();
    }

    /**
     * @param string $modelEntity
     * @return string
     */
    protected function getTableName($modelEntity)
    {
        return $this->getResources()->getTableName($modelEntity);
    }

    /**
     * @param array $entityIds
     * @return float[][]
     */
    protected function getEntityPriceBatch(array $entityIds)
    {
        if (count($entityIds) == 0) {
            return [];
        }

        $collectionSelect = $this->createProductCollection()
            ->addAttributeToFilter('entity_id', ['in' => $entityIds])
            ->addPriceData(0, $this->storeManager->getStore($this->storeId)->getWebsiteId())
            ->getSelect()
            ->reset(Zend_Db_Select::COLUMNS)
            ->columns([
                'entity_id',
                'price' => new Zend_Db_Expr('IF(price_index.final_price IS NOT NULL AND price_index.final_price != 0, price_index.final_price, price_index.min_price)'),
                'old_price' => 'price_index.price',
                'min_price' => 'price_index.min_price',
                'max_price' => 'price_index.max_price',
            ]);
        $collectionQuery = $collectionSelect->query();

        $result = [];
        while ($row = $collectionQuery->fetch()) {
            $result[(int) $row['entity_id']] = $row;
        }

        return $result;
    }

    /**
     * @param array $entityIds
     * @return int[][]
     */
    protected function getEntityCategoriesBatch(array $entityIds)
    {
        if (count($entityIds) == 0) {
            return [];
        }

        $query = $this->getConnection()
            ->select()
            ->from($this->getTableName(CategoryProductAbstractAction::MAIN_INDEX_TABLE), ['category_id', 'product_id'])
            ->where('product_id IN(' . join(',', $entityIds) . ')')
            ->query();

        $result = [];
        while ($row = $query->fetch()) {

            $entityId = (int) $row['product_id'];
            if (isset($result[$entityId])) {
                $result[$entityId] = [];
            }

            $result[$entityId][] = (int) $row['category_id'];
        }
        return $result;
    }

    /**
     * @param array $groupedEntityIds
     * @param array $allEntityIds
     * @return int[]
     */
    protected function getEntityStockBatch(array $groupedEntityIds, array $allEntityIds)
    {
        if (count($groupedEntityIds) == 0) {
            return [];
        }

        $query = $this->getConnection()
            ->select()
            ->from($this->getTableName('cataloginventory_stock_item'), ['product_id', 'qty'])
            ->where('product_id IN(' . join(',', $allEntityIds) . ')')
            ->query();

        $map = [];
        while ($row = $query->fetch()) {
            $productId = (int) $row['product_id'];
            $map[$productId] = (float) $row['qty'];
        }

        $result = [];
        foreach ($groupedEntityIds as $group) {
            foreach ($group as $parentId => $childIds) {
                $parentResult = [];
                if (isset($map[$parentId])) {
                    $parentResult[] = max(0, $map[$parentId]);
                }

                foreach ($childIds as $childId) {
                    if (isset($map[$childId])) {
                        $parentResult[] = max(0, $map[$childId]);
                    }
                }

                switch ($this->config->getStockCalculation()) {
                    case StockCalculation::OPTION_MAX:
                        $result[$parentId] = max($parentResult);
                        break;
                    case StockCalculation::OPTION_MIN:
                        $result[$parentId] = min($parentResult);
                        break;
                    case StockCalculation::OPTION_SUM:
                    default:
                        $result[$parentId] = array_sum($parentResult);
                        break;
                }

            }
        }

        return $result;
    }

    /**
     * @param array $groupedEntityIds
     * @param array $allEntityIds
     * @return array[]
     */
    protected function getEntityExtraAttributesBatch(array $groupedEntityIds, array $allEntityIds)
    {
        if (count($groupedEntityIds) == 0) {
            return [];
        }

        

        return [];
    }

    /**
     * @param array $entities
     * @return Generator
     */
    protected function processBatch(array $entities)
    {
        $entityIds = array_keys($entities);
        $prices = $this->getEntityPriceBatch($entityIds);
        $categories = $this->getEntityCategoriesBatch($entityIds);

        $groupedEntityIds = $this->groupEntityIdsByType($entities);
        $allEntityIds = $entityIds;
        array_walk_recursive($groupedEntityIds, function ($v) use (&$allEntityIds) { $allEntityIds[] = $v; });
        $stock = $this->getEntityStockBatch($groupedEntityIds, $allEntityIds);
        $extraAttributes = $this->getEntityExtraAttributesBatch($groupedEntityIds, $allEntityIds);

        foreach ($entities as $entityId => $entity) {

            $name = $entity['name'];
            $entityCategories = isset($categories[$entityId]) ? $categories[$entityId] : [];
            $entityStock = isset($stock[$entityId]) ? $stock[$entityId] : 0;
            $entityPrice = isset($prices[$entityId]) ? $prices[$entityId] : 0;
            $attributes = isset($extraAttributes[$entityId]) ? $extraAttributes[$entityId] : [];

            // Combine price data
            $entity['old_price'] = $entityPrice['old_price'];
            $entity['min_price'] = $entityPrice['min_price'];
            $entity['max_price'] = $entityPrice['max_price'];

            // Combine extra attributes
            foreach ($entity as $attribute => $value) {
                if (in_array($attribute, ['name', 'entity_id'])) {
                    continue;
                }
                $attributes[] = ['attribute' => $attribute, 'value' => $value];
            }

            // yield return single entity response from batch
            yield [
                'entity_id' => $entityId,
                'name' => $name,
                'price' => $entityPrice['price'],
                'stock' => $entityStock,
                'categories' => $entityCategories,
                'attributes' => $attributes,
            ];
        }
    }

    /**
     * @param int[] $parentIds
     * @return array[]
     */
    protected function getBundleChildIds(array $parentIds)
    {
        $connection = $this->getConnection();
        
        $select = $connection->select()
            ->from($this->getTableName('catalog_product_bundle_selection'), ['product_id', 'parent_product_id'])
            ->where('parent_product_id IN (?)', $parentIds);
        
        $query = $select->query();
        $result = array_combine($parentIds, array_fill(0, count($parentIds), []));
        while ($row = $query->fetch()) {
            $parentId = (int) $row['parent_product_id'];
            $result[$parentId][] = (int) $row['product_id'];
        }
        return $result;
    }

    /**
     * @param int[] $parentIds
     * @return array[]
     */
    protected function getLinkChildIds(array $parentIds, $typeId)
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from($this->getTableName('catalog_product_link'), ['linked_product_id', 'product_id'])
            ->where('link_type_id = ?', $typeId)
            ->where('product_id IN (?)', $parentIds);

        $query = $select->query();
        $result = array_combine($parentIds, array_fill(0, count($parentIds), []));
        while ($row = $query->fetch()) {
            $parentId = (int) $row['product_id'];
            $result[$parentId][] = (int) $row['linked_product_id'];
        }
        return $result;
    }

    /**
     * @param int[] $parentIds
     * @return array[]
     */
    protected function getConfigurableChildIds(array $parentIds)
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from($this->getTableName('catalog_product_super_link'), ['product_id', 'parent_id'])
            ->where('parent_id IN (?)', $parentIds);

        $query = $select->query();
        $result = array_combine($parentIds, array_fill(0, count($parentIds), []));
        while ($row = $query->fetch()) {
            $parentId = (int) $row['parent_id'];
            $result[$parentId][] = (int) $row['product_id'];
        }
        return $result;
    }

    /**
     * @param array $entities
     * @return array
     */
    protected function groupEntityIdsByType(array $entities)
    {
        $groups = [];
        foreach ($entities as $entity) {
            if (!isset($groups[$entity['type_id']])) {
                $groups[$entity['type_id']] = [];
            }

            $groups[$entity['type_id']][$entity['entity_id']] = [];
        }

        $types = $this->productType;
        foreach ($groups as $typeId => &$group) {
            // Create fake product type to trick type factory to use getTypeId
            /** @var Product $fakeProduct */
            $fakeProduct = new DataObject(['type_id' => $typeId]);
            $type = $types->factory($fakeProduct);

            if (!$type->isComposite($fakeProduct)) {
                continue;
            }

            $parentIds = array_keys($group);
            if ($type instanceof BundleType) {
                $childrenIds = $this->getBundleChildIds($parentIds);
            } elseif ($type instanceof GroupType) {
                $childrenIds = $this->getLinkChildIds($parentIds, Link::LINK_TYPE_GROUPED);
            } elseif ($type instanceof ConfigurableType) {
                $childrenIds = $this->getConfigurableChildIds($parentIds);
            } else {
                $childrenIds = [];
                foreach ($parentIds as $parentId) {
                    $childrenIds[$parentId] = $type->getChildrenIds($parentId, false);
                }
            }

            $group = $childrenIds;
        }

        return $groups;
    }
}