<?php
namespace DiigoImport\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;

class DiigoImportRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'diigo-import';
    }

    public function getJsonLdType()
    {
        return 'o-module-diigo_import:DiigoImport';
    }

    public function getJsonLd()
    {
        return [
            'o:job' => $this->job()->getReference(),
            'o-module-diigo_import:undo_job' => $this->undoJob()->getReference(),
            'o-module-diigo_import:name' => $this->resource->getName(),
            'o-module-diigo_import:url' => $this->resource->getUrl(),
            'o-module-diigo_import:version' => $this->resource->getVersion(),
        ];
    }

    public function job()
    {
        return $this->getAdapter('jobs')
            ->getRepresentation($this->resource->getJob());
    }

    public function undoJob()
    {
        return $this->getAdapter('jobs')
            ->getRepresentation($this->resource->getUndoJob());
    }

    public function version()
    {
        return $this->resource->getVersion();
    }

    public function name()
    {
        return $this->resource->getName();
    }

    public function libraryUrl()
    {
        return $this->resource->getUrl();
    }

    public function importItemCount($action='create')
    {
        $expr = new Comparison('action', '=', $action);
        $criteria = new Criteria();
        $criteria->where($expr);
        return $this->resource->getImportItems()->matching($criteria)->count();
        return $this->resource->getImportItems()->count();
    }
}
