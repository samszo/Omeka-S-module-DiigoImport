<?php
namespace DiigoImport\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class DiigoImportItemRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLdType()
    {
        return 'o-module-diigo_import:DiigoImportItem';
    }

    public function getJsonLd()
    {
        return [
            'o-module-diigo_import:import' => $this->import()->getReference(),
            'o:item' => $this->job()->getReference(),
            'o-module-diigo_import:action' => $this->resource->getAction(),
        ];
    }

    public function import()
    {
        return $this->getAdapter('diigo_imports')
            ->getRepresentation($this->resource->getImport());
    }

    public function item()
    {
        return $this->getAdapter('items')
            ->getRepresentation($this->resource->getItem());
    }

    public function action()
    {
        return $this->getAdapter('action')
            ->getRepresentation($this->resource->getAction());
    }

}
