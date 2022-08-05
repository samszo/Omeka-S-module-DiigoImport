<?php
namespace DiigoImport\Api\Adapter;

use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class DiigoImportAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'diigo_imports';
    }

    public function getRepresentationClass()
    {
        return \DiigoImport\Api\Representation\DiigoImportRepresentation::class;
    }

    public function getEntityClass()
    {
        return \DiigoImport\Entity\DiigoImport::class;
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();

        if (isset($data['o:job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o:job']['o:id']);
            $entity->setJob($job);
        }
        if (isset($data['o-module-diigo_import:undo_job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o-module-diigo_import:undo_job']['o:id']);
            $entity->setUndoJob($job);
        }

        if (isset($data['o-module-diigo_import:version'])) {
            $entity->setVersion($data['o-module-diigo_import:version']);
        }
        if (isset($data['o-module-diigo_import:name'])) {
            $entity->setName($data['o-module-diigo_import:name']);
        }
        if (isset($data['o-module-diigo_import:url'])) {
            $entity->setUrl($data['o-module-diigo_import:url']);
        }
    }
}
