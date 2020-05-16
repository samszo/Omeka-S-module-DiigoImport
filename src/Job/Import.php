<?php
namespace DiigoImport\Job;

use DateTime;
use Omeka\Job\AbstractJob;
use Omeka\Job\Exception;
use Zend\Http\Client;
use Zend\Http\Response;
use DiigoImport\Diigo\Url;

class Import extends AbstractJob
{
    /**
     * Diigo API client
     *
     * @var Client
     */
    protected $client;

    /**
     * Diigo API URL
     *
     * @var Url
     */
    protected $url;

    /**
     * Vocabularies to cache.
     *
     * @var array
     */
    protected $vocabularies = [
        'dcterms'       => 'http://purl.org/dc/terms/',
        'dctype'        => 'http://purl.org/dc/dcmitype/',
        'bibo'          => 'http://purl.org/ontology/bibo/',
        'skos'          => 'http://www.w3.org/2004/02/skos/core#',
        'foaf'          => 'http://xmlns.com/foaf/0.1/',
        'schema'        => 'http://schema.org/',        
        'oa'            => 'http://www.w3.org/ns/oa#',        
        'dbpedia-owl'   => 'http://dbpedia.org/ontology/',        
        'cito'          => 'http://purl.org/spar/cito',        
    ];

    /**
     * Cache of selected Omeka resource classes
     *
     * @var array
     */
    protected $resourceClasses = [];

    /**
     * Cache of selected Omeka resource template
     *
     * @var array
     */
    protected $resourceTemplate = [];

    /**
     * Cache of selected Omeka properties
     *
     * @var array
     */
    protected $properties = [];

    /**
     * Priority map between Diigo item types and Omeka resource classes
     *
     * @var array
     */
    protected $itemTypeMap = [];

    /**
     * Priority map between Diigo item fields and Omeka properties
     *
     * @var array
     */
    protected $itemFieldMap = [];

    /**
     * Priority map between Diigo creator types and Omeka properties
     *
     * @var array
     */
    protected $creatorTypeMap = [];

    //Ajout samszo
    /**
     * proriété pour gérer les personnes
     *
     * @var array
     */
    protected $persons = [];
    /**
     * proriété pour gérer les tags
     *
     * @var array
     */
    protected $tags = [];
    /**
     * proriété pour gérer les annotations
     *
     * @var array
     */
    protected $annotations = [];
    /**
     * objet pour gérer les logs
     *
     * @var object
     */
    protected $logger;
    /**
     * objet pour gérer l'api
     *
     * @var object
     */
    protected $api;
    /**
     * proriété pour gérer l'identifiant de l'import
     *
     * @var array
     */
    protected $idImport;
    /**
     * proriété pour gérer l'identifiant de la collection
     *
     * @var int
     */
    protected $itemSet;


    /**
     * Perform the import.
     *
     * Accepts the following arguments:
     *
     * - itemSet:       The Diigo item set ID (int)
     * - import:        The Omeka Diigo import ID (int)
     * - user:          The Diigo library type 
     * - apiKey:        The Zotero API key (string)
     * - importFiles:   Whether to import file attachments (bool)
     * - timestamp:     The Diigo dateAdded timestamp (UTC) to begin importing (int)
     *
     * Roughly follows Zotero's recommended steps for synchronizing a Zotero Web
     * API client with the Zotero server. But for the purposes of this job, a
     * "sync" only imports parent items (and their children) that have been
     * added to Zotero since the passed timestamp.
     *
     * @see https://www.zotero.org/support/dev/web_api/v3/syncing#full-library_syncing
     */
    public function perform()
    {
        // Raise the memory limit to accommodate very large imports.
        ini_set('memory_limit', '500M');

        $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');


        $this->itemSet = $this->api->read('item_sets', $this->getArg('itemSet'))->getContent();

        $this->cacheResourceClasses();
        $this->cacheResourceTemplate();
        $this->cacheProperties();

        $this->itemTypeMap = $this->prepareMapping('item_type_map');
        $this->itemFieldMap = $this->prepareMapping('item_field_map');
        $this->creatorTypeMap = $this->prepareMapping('creator_type_map');

        $this->setImportClient();
        $this->setImportUrl();

        $apiVersion = $this->getArg('version', 0);
        $apiKey = $this->getArg('apiKey');
        $user = $this->getArg('user');
        $login = $this->getArg('login');
        $pwd = $this->getArg('pwd');
        $this->idImport = $this->getArg('import');
        $numStart = $this->getArg('numStart', 0);


        //cf. https://www.diigo.com/api_dev/docs#section-methods
        $params = [
            'start' => $numStart,
            'count' => 100,
            // trie par date de création pour reprendre l'import en cas d'erreur
            'sort' => 0
            //number 0-3, determines the order of bookmarks to fetch, 0: created_at, 1: updated_at, 2: popularity, 3: hot, defaults to 0            'direction' => 'asc',
        ];
        //boucle tant qu'il y a des items
        while (true) {
            $url = $this->url->items($params);
            $this->logger->info($url);        
            $dItems = json_decode($this->getResponse($url)->getBody(), true);
            if (empty($dItems)) {
                return;
            }
            $this->ajouteItems($dItems);
            $params['start'] += $params['count'];
        }
    }

    /**
     * Ajoute les items d'une requête
     *
     * @param array $tag
     * @param array $oItem
     * @return array
     */
    protected function ajouteItems($dItems)
    {

        //Enregistre les items diigo
        $oItems = [];
        $i = 0;
        foreach ($dItems as $dItem) {
            if ($this->shouldStop()) {
                return;
            }
            $dateAdded = new DateTime($dItem['updated_at']);
            if ($dateAdded->getTimestamp() < $this->getArg('timestamp', 0)) {
                // Only import items added since the passed timestamp. Note
                // that the timezone must be UTC.
                continue;
            }
            
            //création de l'item omeka
            $oItem = [];
            $oItem['o:item_set'] = [['o:id' => $this->itemSet->id()]];
            $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['bibo']['Webpage']->id()]."";
            $oItem = $this->mapValues($dItem, $oItem);

            //vérifie la présence de l'item pour gérer les mises à jour
            $param = array();
            $param['property'][0]['property']= $this->properties['bibo']['uri']->id()."";
            $param['property'][0]['type']='eq';
            $param['property'][0]['text']=$dItem['url']; 
            //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
            $result = $this->api->search('items',$param)->getContent();
            //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
            //$this->logger->info("RECHERCHE COUNT = ".count($result));
            if(count($result)){
                $action = 'update';
                $response = $this->api->update('items', $result[0]->id(), $oItem, []
                    , ['isPartial'=>true, 'continueOnError' => true]);
                //$this->logger->info("UPDATE ITEM".$result[0]->id()." = ".json_encode($result[0]));
            }else{
                $action = 'create';
                $response = $this->api->create('items', $oItem, [], ['continueOnError' => true]);
            }               
            //$this->logger->info("UPDATE ITEM".$result[0]->id()." = ".json_encode($result[0]));
            $oItem = $response->getContent();
            //enregistre la progression du traitement
            $importItem = [
                'o:item' => ['o:id' => $oItem->id()],
                'o-module-diigo_import:import' => ['o:id' => $this->idImport],
                'o-module-diigo_import:diigo_key' => $dItem['url'],
                'o-module-diigo_import:action' => $action,
            ];
            $this->api->create('diigo_import_items', $importItem, [], ['continueOnError' => true]);


            //récupération des tags
            if($dItem['tags']!=""){
                $arrTags = explode(",", $dItem['tags']);
                foreach ($arrTags as $tag){
                    $this->ajouteTag($tag, $oItem);
                }						
            }            

            //récupération des annotations
            foreach ($dItem['annotations'] as $a) {
                $this->ajouteCitation($a, $oItem);
            }

            $i++;        
        }
        //$this->logger->info("ITEMS  = ".json_encode($oItems));        

    }


    /**
     * Ajoute un tag au format skos
     *
     * @param array $tag
     * @param array $oItem
     * @return array
     */
    protected function ajouteTag($tag, $oItem)
    {

        if(isset($this->tags[$tag]))$oTag=$this->tags[$tag];
        else{
            //vérifie la présence de l'item pour gérer la création
            $param = array();
            $param['property'][0]['property']= $this->properties["skos"]["prefLabel"]->id()."";
            $param['property'][0]['type']='eq';
            $param['property'][0]['text']=$tag; 
            //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
            $result = $this->api->search('items',$param)->getContent();
            //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
            //$this->logger->info("RECHERCHE COUNT = ".count($result));
            if(count($result)){
                $oTag = $result[0];
                //$this->logger->info("ID TAG EXISTE".$result[0]->id()." = ".json_encode($result[0]));
            }else{
                $param = [];
                $param['o:resource_class'] = ['o:id' => $this->resourceClasses['skos']['Concept']->id()];
                $valueObject = [];
                $valueObject['property_id'] = $this->properties["dcterms"]["title"]->id();
                $valueObject['@value'] = $tag;
                $valueObject['type'] = 'literal';
                $param[$this->properties["dcterms"]["title"]->term()][] = $valueObject;
                $valueObject = [];
                $valueObject['property_id'] = $this->properties["skos"]["prefLabel"]->id();
                $valueObject['@value'] = $tag;
                $valueObject['type'] = 'literal';
                $param[$this->properties["skos"]["prefLabel"]->term()][] = $valueObject;
                //création du tag
                $result = $this->api->create('items', $param, [], ['continueOnError' => true])->getContent();
                $oTag = $result;
                $importItem = [
                    'o:item' => ['o:id' => $oTag->id()],
                    'o-module-diigo_import:import' => ['o:id' => $this->idImport],
                    'o-module-diigo_import:diigo_key' => $tag,
                    'o-module-diigo_import:action' => 'createTag',
                ];
                $this->api->create('diigo_import_items', $importItem, [], ['continueOnError' => true]);        
                //$this->logger->info("ID TAG CREATE ".$oIdTag." = ".json_encode($result));
            }
            $this->tags[$tag] = $oTag;
        }
        //ajoute la relation à l'item
        $param = [];
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["skos"]["semanticRelation"]->id();
        $valueObject['value_resource_id'] = $oTag->id();
        $valueObject['type'] = 'resource';
        $param[$this->properties["skos"]["semanticRelation"]->term()][] = $valueObject;
        $this->api->update('items', $oItem->id(), $param, []
            , ['isPartial'=>true, 'continueOnError' => true, 'collectionAction' => 'append']);

        return $oTag;
    }
    

    /**
     * creation d'une annotation
     *
     * @param array    $a
     * @return array
     */
    protected function setAnnotation($a)
    {
        $this->persons[$name]=['contribs'=>[]
            ,'item'=>[
                'o:resource_class' => ['o:id' => $class->id()]
            ]];
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["dcterms"]["title"]->id();
        $valueObject['@value'] = $name;
        $valueObject['type'] = 'literal';
        $this->persons[$name]['item'][$this->properties["foaf"]["givenName"]->term()][] = $valueObject;
/*
o:resource_template[o:id]: 2
o:resource_class[o:id]: 112
oa:motivatedBy[0][@value]: tagging
oa:motivatedBy[0][property_id]: 207
oa:motivatedBy[0][type]: customvocab:1
oa:hasBody[0][rdf:value][0][@value]: 13594
oa:hasBody[0][rdf:value][0][property_id]: 189
oa:hasBody[0][rdf:value][0][type]: literal
oa:hasBody[0][oa:hasPurpose][0][@value]: tagging
oa:hasBody[0][oa:hasPurpose][0][property_id]: 200
oa:hasBody[0][oa:hasPurpose][0][type]: customvocab:1
oa:hasTarget[0][oa:hasSource][0][property_id]: 203
oa:hasTarget[0][oa:hasSource][0][type]: resource
oa:hasTarget[0][oa:hasSource][0][value_resource_id]: 4161
oa:hasTarget[0][rdf:type][0][@value]: o:Item
oa:hasTarget[0][rdf:type][0][property_id]: 185
oa:hasTarget[0][rdf:type][0][type]: customvocab:4
oa:hasTarget[0][rdf:value][0][@value]: 4176
oa:hasTarget[0][rdf:value][0][property_id]: 189
oa:hasTarget[0][rdf:value][0][type]: literal
o:is_public: 0
o:is_public: 1   
*/
    }

    /**
     * Ajoute une citation à partir d'une annotation diigo
     *
     * @param array    $ano
     * @param array    $oItemParent
     * @return array
     */
    protected function ajouteCitation($ano, $oItemParent)
    {
        //vérifie la présence de l'item pour gérer la création
        //la clef correspond à : identifiant du parent + ' citation ' + la date created_at 
        $ref = $oItemParent->id().' citation '.$ano['created_at'];
        $param = array();
        $param['property'][0]['property']= $this->properties["dcterms"]["isReferencedBy"]->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$ref; 
        //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
        $result = $this->api->search('items',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
        //$this->logger->info("RECHERCHE COUNT = ".count($result));
        if(count($result)){
            $oCita = $result[0];
            //$this->logger->info("ID TAG EXISTE".$result[0]->id()." = ".json_encode($result[0]));
        }else{
            //récupère les propriétés diigo
            $oItem = $this->mapValues($ano, []);
            //ajoute les propriétés omeka
            $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['cito']['Citation']->id()];
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["dcterms"]["title"]->id();
            $valueObject['@value'] = $ref;
            $valueObject['type'] = 'literal';
            $oItem[$this->properties["dcterms"]["title"]->term()][] = $valueObject;
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["dcterms"]["isPartOf"]->id();
            $valueObject['value_resource_id'] = $oItemParent->id();
            $valueObject['type'] = 'resource';
            $oItem[$this->properties["dcterms"]["isPartOf"]->term()][] = $valueObject;
            //création du tag
            $result = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
            $oCita = $result;
            $importItem = [
                'o:item' => ['o:id' => $oCita->id()],
                'o-module-diigo_import:import' => ['o:id' => $this->idImport],
                'o-module-diigo_import:diigo_key' => $ref,
                'o-module-diigo_import:action' => 'createCita',
            ];
            $this->api->create('diigo_import_items', $importItem, [], ['continueOnError' => true]);        
            //$this->logger->info("ID TAG CREATE ".$oIdTag." = ".json_encode($result));
        }   
        return $oCita;
    }

    public function ajouteImage(){

        //pour un mode avancé
        $this->client->addCookie('gcc_cookie_id','3477d5c247e572cdc3aa7073b295cebc');
        $this->client->addCookie('diigoandlogincookie','f1-.-luckysemiosis-.-20-.-0');
        $this->client->addCookie('CHKIO','7e58e62b93c12022e31eb3df8fb691ce');
        $this->client->addCookie('ditem_sort','updated');
        $this->client->addCookie('_smasher_session','1e67c34d3e85ab13b2fdd1513f0b41a8');
        $this->client->addCookie('outliner.sid','s%3A-dAe3XZThqsf1CSGjG8JeuwMP4EnZQGX.6gS1L6icv%2FiVxgs3RiwKM4PA2tKmm4DqGOy2IM5ZWto');
        $this->client->addCookie('_ga','GA1.2.273677479.1539250002');
        $this->client->addCookie('count','96');
        $this->client->addCookie('_gid','GA1.2.1428775098.1547539861');
        $this->client->addCookie('CACHE_TIP','true');
        $this->client->addCookie('__utma','45878075.273677479.1539250002.1547623534.1547623534.1');
        $this->client->addCookie('__utmc','45878075');
        $this->client->addCookie('__utmz','45878075.1547623534.1.1.utmcsr=(direct)|utmccn=(direct)|utmcmd=(none)');


        $url = "https://www.diigo.com/interact_api/load_user_items?order=update&type=image&page_num=3";
        $response = $this->client->setUri($url)->send();        
    }

    /**
     * Set the HTTP client to use during this import.
     */
    public function setImportClient()
    {

        $this->client = $this->getServiceLocator()->get('Omeka\HttpClient')
            // Decrease the chance of timeout by increasing to 20 seconds,
            // which splits the time between Omeka's default (10) and Diigp's
            // upper limit (30).
            ->setOptions(['timeout' => 20]);
        $this->client->setAuth($this->getArg('login'), $this->getArg('pwd'));

    }

    /**
     * Set the Diigo URL object to use during this import.
     */
    public function setImportUrl()
    {
        $this->url = new Url($this->getArg('apiKey'), $this->getArg('user'));
    }

    /**
     * Get a response from the Diigo API.
     *
     * @param string $url
     * @return Response
     */
    public function getResponse($url)
    {
        $response = $this->client->setUri($url)->send();
        if (!$response->isSuccess()) {
            throw new Exception\RuntimeException(sprintf(
                'Requested "%s" got "%s".', $url, $response->renderStatusLine()
            ));
        }
        return $response;
    }

    /**
     * Cache selected resource classes.
     */
    public function cacheResourceClasses()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUri) {
            $classes = $api->search('resource_classes', [
                'vocabulary_namespace_uri' => $namespaceUri,
            ])->getContent();
            foreach ($classes as $class) {
                $this->resourceClasses[$prefix][$class->localName()] = $class;
            }
        }
    }

    /**
     * Cache selected properties.
     */
    public function cacheProperties()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        foreach ($this->vocabularies as $prefix => $namespaceUri) {
            $properties = $api->search('properties', [
                'vocabulary_namespace_uri' => $namespaceUri,
            ])->getContent();
            foreach ($properties as $property) {
                $this->properties[$prefix][$property->localName()] = $property;
            }
        }
    }

    /**
     * Cache selected resource template.
     */
    public function cacheResourceTemplate()
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $arrRT = ["Annotation"];
        foreach ($arrRT as $label) {
            $rt = $api->search('resource_templates', [
                'label' => $label,
            ])->getContent();

            $this->resourceTemplate[$label]=$rt;
        }
    }

    /**
     * Convert a mapping with terms into a mapping with prefix and local name.
     *
     * @param string $mapping
     * @return array
     */
    protected function prepareMapping($mapping)
    {
        $map = require dirname(dirname(__DIR__)) . '/data/mapping/' . $mapping . '.php';
        foreach ($map as &$term) {
            if ($term) {
                $value = explode(':', $term);
                $term = [$value[0] => $value[1]];
            } else {
                $term = [];
            }
        }
        return $map;
    }

    /**
     * Map Diigo item data to Omeka item values.
     *
     * @param array $diigoItem The Diigo item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapValues(array $diigoItem, array $omekaItem)
    {
        foreach ($diigoItem as $key => $value) {
            if (!$value) {
                continue;
            }
            if (!isset($this->itemFieldMap[$key])) {
                continue;
            }
            foreach ($this->itemFieldMap[$key] as $prefix => $localName) {
                if (isset($this->properties[$prefix][$localName])) {
                    $property = $this->properties[$prefix][$localName];
                    $valueObject = [];
                    $valueObject['property_id'] = $property->id();
                    if ('bibo' == $prefix && 'uri' == $localName) {
                        $valueObject['@id'] = $value;
                        $valueObject['type'] = 'uri';
                    } else {
                        $valueObject['@value'] = $value;
                        $valueObject['type'] = 'literal';
                    }
                    $omekaItem[$property->term()][] = $valueObject;
                    continue 2;
                }
            }
        }
        return $omekaItem;
    }

    /**
     * Map Zotero creator names to the Omeka item values.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return array
     */
    public function mapNameValues(array $zoteroItem, array $omekaItem)
    {
        if (!isset($zoteroItem['data']['creators'])) {
            return $omekaItem;
        }
        $creators = $zoteroItem['data']['creators'];
        foreach ($creators as $creator) {
            $creatorType = $creator['creatorType'];
            if (!isset($this->creatorTypeMap[$creatorType])) {
                continue;
            }
            $name = [];
            if (isset($creator['name'])) {
                $name[] = $creator['name'];
            }
            if (isset($creator['firstName'])) {
                $name[] = $creator['firstName'];
            }
            if (isset($creator['lastName'])) {
                $name[] = $creator['lastName'];
            }
            if (!$name) {
                continue;
            }
            $name = implode(' ', $name);
            foreach ($this->creatorTypeMap[$creatorType] as $prefix => $localName) {
                //ajout samszo                
                if(!isset($this->persons[$name])){
                    $this->ajoutePersonne($name, $creator);                    
                }
                //fin ajout

                if (isset($this->properties[$prefix][$localName])) {
                    $property = $this->properties[$prefix][$localName];
                    $omekaItem[$property->term()][] = [
                        'property_id' => $property->id(),
                        /*ajout samszo
                        '@value' => $name,
                        'type' => 'literal',
                        */
                        'value_resource_id' => $this->persons[$name]['id'],
                        'type' => 'resource'
                    ];
                    continue 2;
                }

            }
        }
        return $omekaItem;
    }

    /**
     * Map an attachment.
     *
     * There are four kinds of Zotero attachments: imported_url, imported_file,
     * linked_url, and linked_file. Only imported_url and imported_file have
     * files, and only when the response includes an enclosure link. For
     * linked_url, the @id URL was already mapped in mapValues(). For
     * linked_file, there is nothing to save.
     *
     * @param array $zoteroItem The Zotero item data
     * @param array $omekaItem The Omeka item data
     * @return string
      */
    public function mapAttachment($zoteroItem, $omekaItem)
    {
        if ('attachment' === $zoteroItem['data']['itemType']
            && isset($zoteroItem['links']['enclosure'])
            && $this->getArg('importFiles')
            && $this->getArg('apiKey')
        ) {
            $property = $this->properties['dcterms']['title'];
            $omekaItem['o:media'][] = [
                'o:ingester' => 'url',
                'o:source'   => $this->url->itemFile($zoteroItem['key']),
                'ingest_url' => $this->url->itemFile(
                    $zoteroItem['key'],
                    ['key' => $this->getArg('apiKey')]
                ),
                $property->term() => [
                    [
                        '@value' => $zoteroItem['data']['title'],
                        'property_id' => $property->id(),
                        'type' => 'literal',
                    ],
                ],
            ];
        }
        return $omekaItem;
    }

    /**
     * Get a URL from the Link header.
     *
     * @param Response $response
     * @param string $rel The relationship from the current document. Possible
     * values are first, prev, next, last, alternate.
     * @return string|null
     */
    public function getLink(Response $response, $rel)
    {
        $linkHeader = $response->getHeaders()->get('Link');
        if (!$linkHeader) {
            return null;
        }
        preg_match_all(
            '/<([^>]+)>; rel="([^"]+)"/',
            $linkHeader->getFieldValue(),
            $matches
        );
        if (!$matches) {
            return null;
        }
        $key = array_search($rel, $matches[2]);
        if (false === $key) {
            return null;
        }
        return $matches[1][$key];
    }
}
