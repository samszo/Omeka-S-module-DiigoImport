<?php
namespace DiigoImport;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;

use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();

        $module = $services->get('Omeka\ModuleManager')->getModule('Generic');
        if ($module && version_compare($module->getIni('version'), '3.0.18', '<')) {
            $translator = $services->get('MvcTranslator');
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('This module requires the module "%s", version %s or above.'), // @translate
                'Generic', '3.0.18'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException($message);
        }
    }

    protected function postUninstall(): void
    {
        $services = $this->getServiceLocator();

        if (!class_exists(\Generic\InstallResources::class)) {
            require_once file_exists(dirname(__DIR__) . '/Generic/InstallResources.php')
                ? dirname(__DIR__) . '/Generic/InstallResources.php'
                : __DIR__ . '/src/Generic/InstallResources.php';
        }

        $installResources = new \Generic\InstallResources($services);
        $installResources = $installResources();

        if (!empty($_POST['remove-vocabulary'])) {
            $prefix = 'cito';
            $installResources->removeVocabulary($prefix);
            $prefix = 'skos';
            $installResources->removeVocabulary($prefix);
            $prefix = 'oa';
            $installResources->removeVocabulary($prefix);
            $prefix = 'schema';
            $installResources->removeVocabulary($prefix);
            $prefix = 'rdf';
            $installResources->removeVocabulary($prefix);
            $prefix = 'jdc';
            $installResources->removeVocabulary($prefix);
        }


        if (!empty($_POST['remove-template'])) {
            $resourceTemplate = 'Diigo highlight';
            $installResources->removeResourceTemplate($resourceTemplate);
            $resourceTemplate = 'Diigo note';
            $installResources->removeResourceTemplate($resourceTemplate);
            $resourceTemplate = 'Actant';
            $installResources->removeResourceTemplate($resourceTemplate);            
        }
    }

    public function warnUninstall(Event $event)
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() != __NAMESPACE__) {
            return;
        }

        $serviceLocator = $this->getServiceLocator();
        $t = $serviceLocator->get('MvcTranslator');

        $vocabularyLabels = 'Citation Typing Ontology" / "Schema" / "SKOS" / "Web Annotation Ontology" / "The RDF Concepts Vocabulary (RDF)" / "Jardin des connaissances"';
        $resourceTemplates = 'Diigo highlight / Diigo note / Actant';

        $html = '<p>';
        $html .= '<strong>';
        $html .= $t->translate('WARNING'); // @translate
        $html .= '</strong>' . ': ';
        $html .= '</p>';

        $html .= '<p>';
        $html .= sprintf(
            $t->translate('If checked, the values of the vocabularies "%s" will be removed too. The class of the resources that use a class of these vocabularies will be reset.'), // @translate
            $vocabularyLabels
        );
        $html .= '</p>';
        $html .= '<label><input name="remove-vocabulary" type="checkbox" form="confirmform">';
        $html .= sprintf($t->translate('Remove the vocabularies "%s"'), $vocabularyLabels); // @translate
        $html .= '</label>';


        $html .= '<p>';
        $html .= sprintf(
            $t->translate('If checked, the resource templates "%s" will be removed too. The resource template of the resources that use it will be reset.'), // @translate
            $resourceTemplates
        );
        $html .= '</p>';
        $html .= '<label><input name="remove-template" type="checkbox" form="confirmform">';
        $html .= sprintf($t->translate('Remove the resource templates "%s"'), $resourceTemplates); // @translate
        $html .= '</label>';

        echo $html;
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

        // Display a warn before uninstalling.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );


    }
}
