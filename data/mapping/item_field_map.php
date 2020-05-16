<?php
// Warning: the mapping is not one-to-one, so some data may be lost when the
// mapping is reverted. You may adapt it to your needs.

return [
    'url'               => 'bibo:uri',
    'shared'            => 'schema:actionStatus',
    'created_at'        => 'dcterms:created',
    'updated_at'        => 'dcterms:dateSubmitted',
    'desc'              => 'dcterms:description',
    'title'             => 'dcterms:title',
    'content'           => 'bibo:content',
    'isPartOf'          => 'dcterms:isPartOf',
    'citation'          => 'dbpedia-owl:quotation',
    'user'              => 'cito:isCompiledBy',
    'isReferencedBy'    => 'dcterms:isReferencedBy',
    'semanticRelation'  => 'skos:semanticRelation',
];
