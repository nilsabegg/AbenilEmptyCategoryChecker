<?php

namespace AbenilEmptyCategoryChecker\Subscriber;

use Enlight\Event\SubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Bundle\SearchBundle\Condition\CategoryCondition;
use Shopware\Bundle\SearchBundle\Condition\CustomerGroupCondition;
use Shopware\Bundle\SearchBundle\Criteria;
//use Shopware\Bundle\StoreFrontBundle\Service\Core\ContextService;
//use Shopware\Bundle\StoreFrontBundle\Struct\ProductContext;
//use Shopware\Components\ProductStream\RepositoryInterface;

class Cron implements SubscriberInterface
{

    private $categoryResource;
    private $config;
    private $connection;
    private $container;

    /**
     * Frontend constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Return Subscribed Events
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return array(
            'Shopware_CronJob_AbenilEmptyCategoryChecker' => 'runCron'
        );
    }

    /**
    *
    */
    private function initCron()
    {
        $this->entityManager = $this->container->get('models');
        $this->categoryResource = \Shopware\Components\Api\Manager::getResource('category');
        $this->connection = $this->container->get('dbal_connection');
        $shop = false;
        if ($this->container->initialized('shop')) {
            $shop = $this->container->get('shop');
        }
        if (!$shop) {
            $shop = $this->container->get('models')->getRepository(\Shopware\Models\Shop\Shop::class)->getActiveDefault();
        }
        $this->config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('AbenilEmptyCategoryChecker', $shop);
    }

    /**
     * Send E-Mail to the support team when a customer changes his
     * address outside of the checkout process to make sure he will
     * receive the catalogue of the season or some promotional vouchers.
     */
    public function runCron()
    {

        $this->initCron();
        $emptyCategories = array();
        $batchSize = 10;
        $batchStart = 0;
        while (($categoryList = $this->getCategoryList($batchStart, $batchSize))) {
            foreach ($categoryList as $category) {
                try{
                    $isCategoryEmpty = $this->isCategoryEmpty($category);
                    if ($isCategoryEmpty === true) {
                        $emptyCategories[] = $category;
                        if ($this->config['deactivateEmptyCategories'] === true) {
                            $this->deactivateCategory($category);
                        }
                    }
                }catch(\Exception $e){
                    echo "Category failed: ".$e->getMessage().PHP_EOL;
                }
            }
            $batchStart += $batchSize;
        }

        if ($this->config['sendMail'] === true) {
            $this->sendMail($emptyCategories);
        }

    }

    private function deactivateCategory($category)
    {
        $categoryId = $category['id'];
        unset($category['id']);
        $category['active'] = false;
        $this->categoryResource->update($categoryId, $category);
    }

    /**
    * @param array $category
    * @return bool
    */
    private function isCategoryEmpty($category)
    {
        if ($category['active'] === false) {
            return false;
        }
        if ((int) $category['articleCount'] <= 0) {
            $productStream = $this->getProductStream($category);
            if ($productStream === null) {
                return true;
            } else {
                return $this->isEmptyProductStream($productStream);
            }
        }

        return false;
    }

    private function isEmptyProductStream($productStream)
    {
        if ($productStream->getType() === 1) {
            return $this->isEmptyFilterProductStream($productStream);
        } elseif ($productStream->getType() === 2) {
            return $this->isEmptySelectionProductStream($productStream);
        }
    }

    private function isEmptySelectionProductStream($productStream)
    {
        $streamRepository = $this->container->get('shopware_product_stream.repository');
        $products = $this->getSelectionProductStreamProducts($productStream->getId());
        if (count($products) >= 1) {
            return false;
        }

        return true;
    }

    private function isEmptyFilterProductStream($productStream)
    {
        /** @var RepositoryInterface $streamRepo */
        $streamRepo = $this->container->get('shopware_product_stream.repository');

        $criteria = new Criteria();
        $sorting = $productStream->getSorting();
        if (null !== $sorting) {
            $sorting = $streamRepo->unserialize($sorting);
            foreach ($sorting as $sort) {
                $criteria->addSorting($sort);
            }
        }
        $conditions = $productStream->getConditions();
        if (null !== $conditions) {
            $conditions = json_decode($conditions, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Could not decode JSON: ' . json_last_error_msg());
            }
            $conditions = $streamRepo->unserialize($conditions);
            foreach ($conditions as $condition) { /* @var $condition \Shopware\Bundle\SearchBundle\ConditionInterface */
                $criteria->addCondition($condition);
            }
        }

        $criteria->offset(0);
        $criteria->limit(1);
        $context = $this->container->get('shopware_storefront.context_service')->createShopContext(1);

        $criteria->addBaseCondition(
            new CustomerGroupCondition([$context->getCurrentCustomerGroup()->getId()])
        );
        $category = $context->getShop()->getCategory()->getId();
        $criteria->addBaseCondition(
            new CategoryCondition([$category])
        );

        $result = $this->container->get('shopware_search.product_search')->search($criteria, $context);
        $products = array_values($result->getProducts());
        if (count($products) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @param int $offset
     * @param int|null $limit
     * @return array|bool
     */
    private function getCategoryList($offset = 0, $limit = null)
    {
        try {
            $categoryList = $this->categoryResource->getList($offset, $limit);
        } catch (\Exception $e) {
            $this->log(__FUNCTION__ . ': ' . $e->getMessage());
        }
        if (
            !isset($categoryList['data'])
            || !is_array($categoryList['data'])
            || count($categoryList['data']) == 0
        ) {
            return false;
        }
        return $categoryList['data'];
    }

    private function getSelectionProductStreamProducts($productStreamId)
    {
        $query = $this->connection->createQueryBuilder();
        $query->select('article_id')
            ->from('s_product_streams_selection', 'p')
            ->where('stream_id = :productStreamId')
            ->setParameter(':productStreamId', $productStreamId);
        return $query->execute()->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function getProductStream($category)
    {
        $categoryRepository = $this->entityManager->getRepository('\Shopware\Models\Category\Category');
        $categoryEntity = $categoryRepository->find($category['id']);
        if (!$categoryEntity) {
            throw new \Exception('Category ID ' . $category['id'] . ' not found');
        }

        return $categoryEntity->getStream();
    }

    private function sendMail($emptyCategories)
    {
        $mail = Shopware()->TemplateMail()->createMail('aEMPTYCATEGORYREPORT', array('emptyCategories' => $emptyCategories, 'emptyCategoriesCount' => count($emptyCategories)));
        $mail->addTo($this->config['recipient']);
        $mail->send();
    }
}