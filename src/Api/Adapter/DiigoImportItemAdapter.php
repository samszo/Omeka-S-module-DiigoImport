<?php
namespace DiigoImport\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class DiigoImportItemAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'diigo_import_items';
    }

    public function getRepresentationClass()
    {
        return \DiigoImport\Api\Representation\DiigoImportItemRepresentation::class;
    }

    public function getEntityClass()
    {
        return \DiigoImport\Entity\DiigoImportItem::class;
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();
        if ($data['o:item']['o:id']) {
            $item = $this->getAdapter('items')->findEntity($data['o:item']['o:id']);
            $entity->setItem($item);
        }
        if (isset($data['o-module-diigo_import:import']['o:id'])) {
            $import = $this->getAdapter('diigo_imports')->findEntity($data['o-module-diigo_import:import']['o:id']);
            $entity->setImport($import);
        }
        if ($data['o-module-diigo_import:diigo_key']) {
            $entity->setDiigoKey($data['o-module-diigo_import:diigo_key']);
        }
        if ($data['o-module-diigo_import:action']) {
            $entity->setAction($data['o-module-diigo_import:action']);
        }
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['import_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.import',
                $this->createNamedParameter($qb, $query['import_id']))
            );
        }
    }
}
