<?php
/**
 * Created by PhpStorm.
 * User: Nils Abegg
 * Date: 14.08.2018
 * Time: 15:00
 */

namespace AbenilEmptyCategoryChecker;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Components\Plugin\Context\UpdateContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AbenilEmptyCategoryChecker extends Plugin
{

    public function build(ContainerBuilder $container)
    {
        $container->setParameter('abenil_empty_category_checker.plugin_dir', $this->getPath());
        parent::build($container);
    }

    /**
     * Add empty category checker cron
     */
    public function addCron()
    {
        /** @var Connection $connection */
        $connection = $this->container->get('dbal_connection');
        $count = $connection->fetchColumn("SELECT COUNT(*) FROM s_crontab WHERE action = 'Shopware_CronJob_AbenilEmptyCategoryChecker'");
        if($count == 0) {
            $connection->insert(
                's_crontab',
                [
                    'name'             => 'Set Artikel Verknüpfung aktualisieren',
                    'action'           => 'Shopware_CronJob_AbenilEmptyCategoryChecker',
                    'next'             => new \DateTime(),
                    'start'            => null,
                    '`interval`'       => 86400,
                    'active'           => 1,
                    'disable_on_error' => 0,
                    'end'              => new \DateTime(),
                    'pluginID'         => null,
                ],
                [
                    'next' => 'datetime',
                    'end' => 'datetime',
                ]
            );
        }
    }

    /**
     * Add empty category checker email template
     */
    public function addEmailTemplate()
    {
        /** @var Connection $connection */
        $connection = $this->container->get('dbal_connection');
        $count = $connection->fetchColumn("SELECT COUNT(*) FROM s_core_config_mails WHERE name = 'aEMPTYCATEGORYREPORT'");
        if($count == 0) {
            $connection->insert(
                's_core_config_mails',
                [
                    'name'             => 'aEMPTYCATEGORYREPORT',
                    'frommail'           => '{config name=mail}',
                    'fromname'             => '{config name=shopName}',
                    'subject'            => 'Leere Kategorien Check: {$emptyCategoriesCount}',
                    'content'       => '{include file="string:{config name=emailheaderplain}"}

Hallo Bob,

Es wurden {$emptyCategoriesCount} Kategorie(n) deaktivert.
Hier findest du eine Auflistung der deaktivierten Kategorien.

ID     Name     
{foreach from=$emptyCategories item=sCategory key=key}
{$sCategory.id}      {$sCategory.name}
{/foreach}

{include file="string:{config name=emailfooterplain}"}',
                    'isHtml'           => 0,
                    'mailtype' => 1,
                    'context'              => 'a:2:{s:15:"emptyCategories";a:1:{i:0;a:8:{s:2:"id";i:27;s:6:"active";b:1;s:4:"name";s:20:"In Kürze verfügbar";s:8:"position";i:0;s:8:"parentId";i:10;s:7:"mediaId";N;s:13:"childrenCount";s:1:"0";s:12:"articleCount";s:1:"0";}}s:20:"emptyCategoriesCount";i:1;}',
                ]
            );
        }
    }

    public function install(InstallContext $context)
    {
    	parent::install($context);
        $this->addCron();
        $this->addEmailTemplate();
    }

    public function update(UpdateContext $context)
    {
    	parent::update($context);
        $this->addCron();
        $this->addEmailTemplate();
    }
}