<?php
namespace DiigoImport;

use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services)
    {
        $conn = $services->get('Omeka\Connection');
        $conn->exec('SET FOREIGN_KEY_CHECKS=0;');
        $conn->exec('CREATE TABLE diigo_import (id INT AUTO_INCREMENT NOT NULL, job_id INT DEFAULT NULL, undo_job_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, url VARCHAR(255) NOT NULL, version INT NOT NULL, UNIQUE INDEX UNIQ_DIIGO_JOB (job_id), UNIQUE INDEX UNIQ_DIIGO_JOBUNDO (undo_job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;');
        $conn->exec('CREATE TABLE diigo_import_item (id INT AUTO_INCREMENT NOT NULL, import_id INT NOT NULL, item_id INT NOT NULL, diigo_key VARCHAR(1000) NOT NULL, action VARCHAR(50) NOT NULL, INDEX IDX_DIIGO_IMPORT (import_id), INDEX IDX_DIIGO_ITEM (item_id), INDEX IDX_DIIGO_ACTION (action), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;');
        $conn->exec('ALTER TABLE diigo_import ADD CONSTRAINT FK_82A3EEB8BE04E_DIGGO FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE CASCADE;');
        $conn->exec('ALTER TABLE diigo_import ADD CONSTRAINT FK_82A3EEB84C27_DIGGO FOREIGN KEY (undo_job_id) REFERENCES job (id) ON DELETE CASCADE;');
        $conn->exec('ALTER TABLE diigo_import_item ADD CONSTRAINT FK_86A2392BB6A2_DIGGO FOREIGN KEY (import_id) REFERENCES diigo_import (id) ON DELETE CASCADE;');
        $conn->exec('ALTER TABLE diigo_import_item ADD CONSTRAINT FK_86A2392B126F_DIGGO FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;');
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $conn = $services->get('Omeka\Connection');
        $conn->exec('SET FOREIGN_KEY_CHECKS=0;');
        $conn->exec('DROP TABLE diigo_import;');
        $conn->exec('DROP TABLE diigo_import_item;');
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.search.query',
            function (Event $event) {
                $query = $event->getParam('request')->getContent();
                if (isset($query['diigo_import_id'])) {
                    $qb = $event->getParam('queryBuilder');
                    $adapter = $event->getTarget();
                    $importItemAlias = $adapter->createAlias();
                    $qb->innerJoin(
                        \DiigoImport\Entity\DiigoImportItem::class, $importItemAlias,
                        'WITH', "$importItemAlias.item = omeka_root.id"
                    )->andWhere($qb->expr()->eq(
                        "$importItemAlias.import",
                        $adapter->createNamedParameter($qb, $query['diigo_import_id'])
                    ));
                }
            }
        );

    }
}
