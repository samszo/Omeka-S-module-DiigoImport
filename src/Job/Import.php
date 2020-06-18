<?php
namespace DiigoImport\Job;

use DateTime;
use Omeka\Job\AbstractJob;
use Omeka\Job\Exception;
use Zend\Http\Client;
use Zend\Http\Response;
use DiigoImport\Diigo\Url;
use DiigoImport\Diigo\Cookies;

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
        'cito'          => 'http://purl.org/spar/cito',        
        'jdc'           => 'https://jardindesconnaissances.univ-paris8.fr/onto/',        
        'rdf'       => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
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

    /**
     * proriété pour gérer les actants
     *
     * @var array
     */
    protected $actant = [];
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
     * proriété pour gérer les doublons est sortir du traitement des items
     *
     * @var array
     */
    protected $doublons;


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
        $this->tempFileFactory = $this->getServiceLocator()->get('Omeka\File\TempFileFactory');
        

        $this->itemSet = $this->api->read('item_sets', $this->getArg('itemSet'))->getContent();

        $this->cacheResourceClasses();
        $this->cacheResourceTemplate();
        $this->cacheProperties();

        $this->itemTypeMap = $this->prepareMapping('item_type_map');
        $this->itemFieldMap = $this->prepareMapping('item_field_map');
        $this->creatorTypeMap = $this->prepareMapping('creator_type_map');

        $this->setImportClient();
        $this->setImportUrl();

        //récupère les arguments
        $apiVersion = $this->getArg('version', 0);
        $apiKey = $this->getArg('apiKey');
        $user = $this->getArg('user');
        $login = $this->getArg('login');
        $pwd = $this->getArg('pwd');
        $this->idImport = $this->getArg('import');
        $numStart = $this->getArg('numStart', 0);
        $importFiles = $this->getArg('importFiles');
        $what = $this->getArg('what');

        //vérifie s'il faut passer par l'API ou par les liste outliner
        if($importFiles){
            $c = new Cookies();
            $this->client = $c->set($this->client);
            $continue = true;
            $this->doublons = [];
            $params = [
                'page_num' => $numStart,
                'type' => 'all',
                'sort' => 'updated'
            ];
            if($what)$params['what']=$what;

            while (true) {
                if($what) $url = $this->url->itemsOutlinerWhat($params);
                else $url = $this->url->itemsOutliner($params);
                $this->logger->info($url);        
                $rs = json_decode($this->getResponse($url)->getBody(), true);
                $continue = $this->ajouteItemsOut($rs['items']);
                $this->logger->info(__METHOD__.' continue = '.$continue);        
                if (!$continue) return;
                $params['page_num']++;
            }                        
        }else{
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
        return;
    }

    /**
     * Ajoute les items d'une requête Outliner
     *
     * @param array $tag
     * @param array $oItem
     * @return boolean
     */
    protected function ajouteItemsOut($dItems)
    {   

        //Enregistre les items diigo venu d'un outliner
        $oItems = [];
        $date = new DateTime();        
        $i = 0;
        foreach ($dItems as $dItem) {
            if ($this->shouldStop()) {
                return false;
            }
            if(isset($this->doublons[$dItem['url']])){
                $this->logger->info(__METHOD__." DEJA TRAITE".$dItem['url']);        
                return false;
            }else{
                $this->doublons[$dItem['url']]=1;
            } 


            //$this->logger->info(__METHOD__." ".$i.' : '.$dItem['title'],$dItem);        
            $dateAdded = $date->setTimestamp($dItem['updated_at']);
            if ($dateAdded->getTimestamp() < $this->getArg('timestamp', 0)) {
                // Only import items added since the passed timestamp. Note
                // that the timezone must be UTC.
                return false;
            }
            $dItem['updated_at'] = $dateAdded->format('Y-m-d H:i:sP');
            $dItem['created_at'] = $date->setTimestamp($dItem['created_at'])->format('Y-m-d H:i:sP');            
            $this->logger->info(__METHOD__." ".$i." : ".$dateAdded->format('Y-m-d H:i:sP')." : ".$dItem['url']);        
            
            //récupère l'actant
            if(!isset($this->actant[$dItem['u_name']]))$this->actant[$dItem['u_name']] = $this->ajouteActant($dItem['u_name'], $dItem['real_name']);        
            $dItem['user']=$this->actant[$dItem['u_name']];
            
            //création de l'item omeka
            $oItem = [];
            $oItem = $this->mapValues($dItem, $oItem);
            $oItem['o:item_set'] = [['o:id' => $this->itemSet->id()]];
            $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['bibo']['Webpage']->id()];
            //vérifie le status de l'url
            $oItem[$this->properties["schema"]["serverStatus"]->term()][] = [
                'property_id' => $this->properties["schema"]["serverStatus"]->id(),
                '@value' => $this->getStatus($dItem['url'])."",
                'type' => 'literal'                    
            ];
            //$this->logger->info(__METHOD__." ".$i." : ",$oItem);        

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
                    $oTag = $this->ajouteTag($tag, $oItem);
                    $this->ajouteAnnotation($oItem, $dItem['user'], $oTag);
                }						
            }            

            //récupération des annotations
            $iAno = 0; $images = [];
            foreach ($dItem['annotations'] as $a) {
                if(!isset($a['created_at']))$a['created_at']=$dItem['created_at'];
                if(isset($a['type_name']) && $a['type_name']=="image"){
                    $images[]=$a;
                }else{
                    $this->ajouteCitation($a, $oItem, $iAno);
                }
                $iAno ++;
            }
            //ajoute les images
            $this->ajouteImages($images, $oItem);
            $this->logger->info(__METHOD__." FIN Item ".$i);        
            
            $i++;        
        }
        $this->logger->info(__METHOD__." FIN");        
        return $i;
    }


     /** Ajoute un actant dans omeka
     *
     * @param string $id
     * @param string $username
     * @return o:item
     */
    //TODO:mettre cette méthode dans un module JDC
    protected function ajouteActant($id, $username)
    {
        //vérifie la présence de l'item pour gérer les mises à jour
        $param = array();
        $param['property'][0]['property']= $this->properties["foaf"]["accountName"]->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$username; 
        //$this->logger->info("RECHERCHE PARAM = ".json_encode($param));
        $result = $this->api->search('items',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM",$result);
        if(count($result)){
            //TODO:mettre à jour l'actant
            return $result[0];
        }else{           
            $oItem = [];
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["foaf"]["accountName"]->id();
            $valueObject['@value'] = $username;
            $valueObject['type'] = 'literal';
            $oItem[$this->properties["foaf"]["accountName"]->term()][] = $valueObject;    
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["foaf"]["account"]->id();
            $valueObject['@value'] = "Diigo";
            $valueObject['type'] = 'literal';
            $oItem[$this->properties["foaf"]["account"]->term()][] = $valueObject;    
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["schema"]["identifier"]->id();
            $valueObject['@value'] = $id."";
            $valueObject['type'] = 'literal';
            $oItem[$this->properties["schema"]["identifier"]->term()][] = $valueObject;    
            $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['jdc']['Actant']->id()];
            $oItem['o:resource_template'] = ['o:id' => $this->resourceTemplate['Actant']->id()];
            $valueObject = [];
            $valueObject['property_id'] = $this->properties["foaf"]["accountServiceHomepage"]->id();
            $valueObject['@id'] = "https://www.diigo.com/user/".$id;
            $valueObject['type'] = 'uri';
            
            $this->logger->info("ajouteActant",$oItem);            
            //création de l'actant
            $result = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
            //TODO:compter le nombre d'actant
            return $result;
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
            
            //valorisation des propriété de l'item omeka
            $oItem = [];
            $oItem['o:item_set'] = [['o:id' => $this->itemSet->id()]];
            $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['bibo']['Webpage']->id()]."";
            $oItem = $this->mapValues($dItem, $oItem);

            //vérifie le status de l'url
            $oItem[$this->properties["schema"]["serverStatus"]->term()][] = [
                'property_id' => $this->properties["schema"]["serverStatus"]->id(),
                '@value' => $this->getStatus($dItem['url'])."",
                'type' => 'literal'                    
            ];
            $this->logger->info("ITEM ".$i, $oItem);

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
    //TODO:mettre cette méthode dans un module JDC
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
     * Ajoute une annotation au format open annotation
     *
     * @param  o:item $doc
     * @param  o:item $act
     * @param  o:item $tag
     * @return array
     */
    protected function ajouteAnnotation($doc, $act, $tag)
    {
        $ref = "idDoc:".$doc->id()
        ."_idActant:".$act->id()
        ."_idTag:".$tag->id();
        $this->logger->info("ajouteAnnotation ".$ref);
        
        //vérifie la présence de l'item pour gérer la création ou la mise à jour
        $param = array();
        $param['property'][0]['property']= $this->properties["dcterms"]["isReferencedBy"]->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$ref; 
        $result = $this->api->search('annotations',$param)->getContent();
        //$this->logger->info("RECHERCHE ITEM = ".json_encode($result));
        //$this->logger->info("RECHERCHE COUNT = ".count($result));
        $update = false;
        if(count($result)){
            $update = true;
            $idAno = $result[0]->id();
        }            

        //création de l'annotation       
        $oItem = [];

        //référence
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["dcterms"]["isReferencedBy"]->id();
        $valueObject['@value'] = $ref;
        $valueObject['type'] = 'literal';
        $oItem[$this->properties["dcterms"]["isReferencedBy"]->term()][] = $valueObject;    

        //motivation
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["oa"]["motivatedBy"]->id();
        $valueObject['@value'] = 'tagging';
        $valueObject['type'] = 'customvocab:'.$this->customVocab['Annotation oa:motivatedBy'];
        $oItem[$this->properties["oa"]["motivatedBy"]->term()][] = $valueObject;    

        //annotator = actant
        $valueObject = [];                
        $valueObject['value_resource_id']=$act->id();        
        $valueObject['property_id']=$this->properties["dcterms"]["creator"]->id();
        $valueObject['type']='resource';    
        $oItem['dcterms:creator'][] = $valueObject;    

        //source = doc 
        $valueObject = [];                
        $valueObject['property_id']=$this->properties["oa"]["hasSource"]->id();
        $valueObject['type']='resource';
        $valueObject['value_resource_id']=$doc->id();
        $oItem['oa:hasSource'][] = $valueObject;    

        //body = texte explicatif
        $valueObject = [];                
        $valueObject['rdf:value'][0]['@value']=$act->displayTitle()
            .' a taggé le document '.$doc->displayTitle()
            .' avec le tag '.$tag->displayTitle();        
        $valueObject['rdf:value'][0]['property_id']=$this->properties["rdf"]["value"]->id();
        $valueObject['rdf:value'][0]['type']='literal';    
        $valueObject['oa:hasPurpose'][0]['@value']='classifying';
        $valueObject['oa:hasPurpose'][0]['property_id']=$this->properties["oa"]["hasPurpose"]->id();
        $valueObject['oa:hasPurpose'][0]['type']='customvocab:'.$this->customVocab['Annotation Body oa:hasPurpose'];
        $oItem['oa:hasBody'][] = $valueObject;    

        //target = tag 
        $valueObject = [];                
        $valueObject['rdf:value'][0]['value_resource_id']=$tag->id();        
        $valueObject['rdf:value'][0]['property_id']=$this->properties["rdf"]["value"]->id();
        $valueObject['rdf:value'][0]['type']='resource';    
        $valueObject['rdf:type'][0]['@value']='o:Item';        
        $valueObject['rdf:type'][0]['property_id']=$this->properties["rdf"]["type"]->id();
        $valueObject['rdf:type'][0]['type']='customvocab:'.$this->customVocab['Annotation Target rdf:type'];            
        $oItem['oa:hasTarget'][] = $valueObject;    

        //type omeka
        $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['oa']['Annotation']->id()];
        $oItem['o:resource_template'] = ['o:id' => $this->resourceTemplate['Annotation']->id()];

        if($update){
            $result = $this->api->update('annotations', $idAno, $oItem, []
                , ['isPartial'=>true, 'continueOnError' => true])->getContent();
        }else{
            //création de l'annotation
            $result = $this->api->create('annotations', $oItem, [], ['continueOnError' => true])->getContent();        
        }        

        //enregistrer le nombre de création et d'update    
        $importItem = [
            'o:item' => ['o:id' => $doc->id()],
            'o-module-diigo_import:import' => ['o:id' => $this->idImport],
            'o-module-diigo_import:diigo_key' => $ref,
            'o-module-diigo_import:action' => $update ? 'updateAnnotation' : 'createAnnotation'
        ];
        $this->api->create('diigo_import_items', $importItem, [], ['continueOnError' => true]);        


        return $result;

    }
    

    /**
     * Ajoute une citation à partir d'une annotation diigo
     *
     * @param array    $ano
     * @param array    $oItemParent
     * 
     * @return array
     */
    //TODO:mettre cette méthode dans un module JDC
    protected function ajouteCitation($ano, $oItemParent)
    {
        //vérifie la présence de l'item pour gérer la création
        //création de la la clef
        $ano['isReferencedBy'] = $oItemParent->id().' citation '.$ano['created_at'];
        if(isset($ano['user_id']))$ano['isReferencedBy'] = $ano['user_id'];
        if(isset($ano['link_id']))$ano['isReferencedBy'] .= '_'.$ano['link_id'];
        if(isset($ano['id']))$ano['isReferencedBy'] .= '_'.$ano['id'];
        
        $param = array();
        $param['property'][0]['property']= $this->properties["dcterms"]["isReferencedBy"]->id()."";
        $param['property'][0]['type']='eq';
        $param['property'][0]['text']=$ano['isReferencedBy']; 
        $result = $this->api->search('items',$param)->getContent();

        //création du titre
        $ano['title'] = 'Citation '.$oItemParent->id();
        if(isset($ano['created_at']))$ano['title'] .= ' '.$ano['created_at'];
        if(isset($ano['link_id'])) $ano['title'] .= ' '.$ano['link_id'];
        if(isset($ano['id'])) $ano['title'] .= ' '.$ano['id'];
        $this->logger->info(__METHOD__.' : '.$ano['isReferencedBy'].' = '.$ano['title']);

        //récupère les propriétés diigo
        $ano['user']=$this->actant[$ano['u_name']];
        $oItem = $this->mapValues($ano, []);            
        //récupère les extra
        if(isset($ano['extra']))$oItem = $this->mapValues($ano['extra'], $oItem);            
        //ajoute les propriétés omeka
        $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['cito']['Citation']->id()];
        $oItem['o:resource_template'] = ['o:id' => $this->resourceTemplate['Diigo highlight']->id()];
        $valueObject = [];
        $valueObject['property_id'] = $this->properties["dcterms"]["isPartOf"]->id();
        $valueObject['value_resource_id'] = $oItemParent->id();
        $valueObject['type'] = 'resource';
        $oItem[$this->properties["dcterms"]["isPartOf"]->term()][] = $valueObject;
        //$this->logger->info(__METHOD__,$oItem);

        if(count($result)){
            $action = 'update';
            $oCita = $this->api->update('items', $result[0]->id(), $oItem, []
                , ['isPartial'=>true, 'continueOnError' => true])->getContent();
        }else{
            $action = 'create';
            //création de la citation
            $oCita = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
            //création des notes
            if(isset($ano['stickyNotes'])){
                foreach ($ano['stickyNotes'] as $n) {
                    $this->ajouteNote($n,$oCita);
                }
            }
        }   
        //mise à jour de la table des imports
        $importItem = [
            'o:item' => ['o:id' => $oCita->id()],
            'o-module-diigo_import:import' => ['o:id' => $this->idImport],
            'o-module-diigo_import:diigo_key' => $ano['isReferencedBy'],
            'o-module-diigo_import:action' => $action.'Cita',
        ];
        $this->api->create('diigo_import_items', $importItem, [], ['continueOnError' => true]);            

        return $oCita;
    }

    /**
     * Ajoute une note à une annotation diigo
     *
     * @param array    $note
     * @param array    $oItemParent
     * 
     * @return array
     */
    //TODO:mettre cette méthode dans un module JDC
    protected function ajouteNote($note, $oItemParent)
    {

        $date = new DateTime();        
        $note["created_at"] = $date->setTimestamp($note['created_at'])->format('Y-m-d H:i:sP');

        $note['user']=$this->actant[$note['u_name']];

        //création du titre
        $note['title'] = 'Note '.$oItemParent->id();
        if(isset($note['link_id'])) $note['title'] .= ' : '.$note['link_id'];
        if(isset($note['id'])) $note['title'] .= '_'.$note['id'];

        //création de la la clef
        $note['isReferencedBy'] = $note['user_id'].'_'.$note['link_id'].'_'.$note['id'];

        //récupère les propriétés diigo
        $oItem = $this->mapValues($note, []);

        //ajoute les propriétés omeka
        $oItem['o:resource_class'] = ['o:id' => $this->resourceClasses['bibo']['Note']->id()];
        $oItem['o:resource_template'] = ['o:id' => $this->resourceTemplate['Diigo note']->id()];

        $valueObject = [];
        $valueObject['property_id'] = $this->properties["dcterms"]["isPartOf"]->id();
        $valueObject['value_resource_id'] = $oItemParent->id();
        $valueObject['type'] = 'resource';
        $oItem[$this->properties["dcterms"]["isPartOf"]->term()][] = $valueObject;

        //création de la note
        $result = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
        $oNote = $result;
        $importItem = [
            'o:item' => ['o:id' => $oNote->id()],
            'o-module-diigo_import:import' => ['o:id' => $this->idImport],
            'o-module-diigo_import:diigo_key' => $note['isReferencedBy'],
            'o-module-diigo_import:action' => 'createNote',
        ];
        $this->api->create('diigo_import_items', $importItem, [], ['continueOnError' => true]);        
        return $oNote;
    }

    /**
     * Set the HTTP client to use during this import.
     */
    public function setImportClient()
    {

        //options pour le ssl inadéquate
        $httpClientOptions = array(
            'adapter' => 'Zend\Http\Client\Adapter\Socket',
            'persistent' => false,
            'sslverifypeer' => false,
            'sslallowselfsigned' => false,
            'sslusecontext' => false,
            'ssl' => array(
                'verify_peer' => false,
                'allow_self_signed' => true,
                'capture_peer_cert' => true,
            ),
            'timeout' => 20
        );
        $this->client = $this->getServiceLocator()->get('Omeka\HttpClient')
            // Decrease the chance of timeout by increasing to 20 seconds,
            // which splits the time between Omeka's default (10) and Diigp's
            // upper limit (30).
            ->setOptions($httpClientOptions);
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
     * Get status URL.
     *
     * @param string $url
     * @return string
     */
    public function getStatus($url)
    {

        $headers = @get_headers($url);
        $statut = is_array($headers) ? explode(' ',$headers[0])[1] : '000';
        //$this->logger->info(__METHOD__,$file_headers);        
        return $statut;
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
        //$this->logger->info(__METHOD__);            
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $arrRT = ["Annotation","Actant","Diigo highlight","Diigo note"];
        foreach ($arrRT as $label) {
            $rts = $api->search('resource_templates', [
                'label' => $label,
            ])->getContent();
            foreach ($rts as $rt) {
                $this->resourceTemplate[$label]=$rt;
            }

        }
        //$this->logger->info(__METHOD__,$this->resourceTemplate);            

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
                    } elseif ('cito' == $prefix && 'isCompiledBy' == $localName) {
                        $valueObject['value_resource_id'] = $value->id();
                        $valueObject['type'] = 'resource';
                    } else {
                        $valueObject['@value'] = $value."";
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
     * Importation des images 
     *
     * @param array $images la liste des images
     * @param array $omekaItem The Omeka item data
     * @return oItem
      */
    public function ajouteImages($images, $omekaItem)
    {
        $oItem = [];
        $tempFiles=[];
        foreach ($images as $ima) {
            $this->logger->info(__METHOD__." ".$ima['download_url']);        
            $tempFile = $this->download($ima['download_url'],$ima['id']);
            if ($tempFile) {
                //$this->logger->info(__METHOD__.' '.$tempFile->getTempPath());        
                $pTitle = $this->properties['dcterms']['title'];
                $pRef = $this->properties['dcterms']['isReferencedBy'];
                //TODO:virer cette verrue  
                $tempFiles[]=$tempFile->getTempPath();
                //$tempPath =  str_replace('/Users/samszo/Sites/','http://localhost/',$tempFile->getTempPath());
                $tempPath =  str_replace('/var/www/html/','http://192.168.20.223/',$tempFile->getTempPath());
                $oItem['o:media'][] = [
                    'o:ingester' => 'url',
                    'o:source'   => $ima['download_url'],
                    'ingest_url' => $tempPath,
                    $pTitle->term() => [
                        [
                            '@value' => $ima['file_title'],
                            'property_id' => $pTitle->id(),
                            'type' => 'literal',
                        ],
                    ],
                    $pRef->term() => [
                        [
                            '@value' => $ref,
                            'property_id' => $pRef->id(),
                            'type' => 'literal',
                        ],
                    ],
                ];                
            }
        }       
        $response = $this->api->update('items', $omekaItem->id(), $oItem, []
            , ['isPartial'=>true, 'continueOnError' => true])->getContent();


        /*TODO:mise à jour de la table des imports
        $importItem = [
            'o:item' => ['o:id' => $response->id()],
            'o-module-diigo_import:import' => ['o:id' => $this->idImport],
            'o-module-diigo_import:diigo_key' => $diigoItem['file_server_id'],
            'o-module-diigo_import:action' => 'creationImage',
        ];
        $this->api->create('diigo_import_items', $importItem, [], ['continueOnError' => true]);        
        */
        //supression des fichiers temporaires
        foreach ($tempFiles as $tp) {
            unlink($tp);
        }
        $this->logger->info(__METHOD__." FIN");        
        return $response;
    }

    //TODO:trouver le moyen de préciser le client au dowloader d'omeka
    /**
     * Download a file from a remote URI.
     * 
     * Pass the $errorStore object if an error should raise an API validation
     * error.
     *
     * @param string|\Zend\Uri\Http $uri
     * @param integer               $id
     * @param null|ErrorStore $errorStore
     * @return TempFile|false False on error
     */
    public function download($uri, $id, ErrorStore $errorStore = null)
    {
        $client = $this->client;

        $tempFile = $this->tempFileFactory->build();
        $tempDir = __DIR__.'/tmp/';
        //$this->logger->info(__METHOD__." ".$tempDir);        
        $tempPath = $tempDir.'diigo_'.$id;
        //$this->logger->info(__METHOD__." ".$tempPath);        
        $tempFile->setTempPath($tempPath);
        //$this->logger->info(__METHOD__." ".$tempFile->getTempPath());        

        // Disable compressed response; it's broken alongside streaming
        $client->getRequest()->getHeaders()->addHeaderLine('Accept-Encoding', 'identity');
        $client->setUri($uri)->setStream($tempFile->getTempPath());

        // Attempt three requests before handling an exception.
        $attempt = 0;
        while (true) {
            try {
                $response = $client->send();
                break;
            } catch (\Exception $e) {
                if (++$attempt === 3) {
                    $this->logger->err((string) $e);
                    if ($errorStore) {
                        $message = new Message(
                            'Error downloading %1$s: %2$s', // @translate
                            (string) $uri, $e->getMessage()
                            );
                        $errorStore->addError('download', $message);
                    }
                    return false;
                }
            }
        }

        if (!$response->isOk()) {
            $message = sprintf(
                'Error downloading %1$s: %2$s %3$s', // @translate
                (string) $uri, $response->getStatusCode(), $response->getReasonPhrase()
                );
            if ($errorStore) {
                $errorStore->addError('download', $message);
            }
            $this->logger->err($message);
            return false;
        }

        return $tempFile;
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
