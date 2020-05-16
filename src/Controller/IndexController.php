<?php
namespace DiigoImport\Controller;

use DateTime;
use DateTimeZone;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\Message;
use Zend\Http\Client;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use DiigoImport\Form\ImportForm;
use DiigoImport\Job;
use DiigoImport\Diigo\Url;

class IndexController extends AbstractActionController
{
    /**
     * @var Client
     */
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function importAction()
    {
        $form = $this->getForm(ImportForm::class);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);

            if ($form->isValid()) {
                $data = $form->getData();
                $timestamp = 0;
                if ($data['addedAfter']) {
                    $addedAfter = new DateTime($data['addedAfter'],
                        new DateTimeZone('UTC'));
                    $timestamp = (int) $addedAfter->format('U');
                }
                $args = [
                    'itemSet'       => $data['itemSet'],
                    'user'          => $data['user'],
                    'apiKey'        => $data['apiKey'],
                    'login'         => $data['login'],
                    'pwd'           => $data['pwd'],
                    'importFiles'   => $data['importFiles'],
                    'numStart'      => $data['numStart'],
                    'version'       => 2,
                    'timestamp'     => $timestamp,
                ];

                if ($args['apiKey'] && !$this->apiKeyIsValid($args)) {
                    $this->messenger()->addError(
                        'Cannot import the Diigo library using the provided API key' // @translate
                    );
                } else {
                    $response = $this->sendApiRequest($args);
                    $body = json_decode($response->getBody(), true);
                    if ($response->isSuccess()) {
                        $import = $this->api()->create('diigo_imports', [
                            'o-module-diigo_import:version' => $args['version'],
                            'o-module-diigo_import:name' => $args['user'],
                            'o-module-diigo_import:url' => 'https://www.diigo.com/user/'.$args['user'],
                        ])->getContent();
                        $args['import'] = $import->id();
                        $job = $this->jobDispatcher()->dispatch(Job\Import::class, $args);
                        $this->api()->update('diigo_imports', $import->id(), [
                            'o:job' => ['o:id' => $job->getId()],
                        ]);
                        $message = new Message(
                            'Importing from Diigo. %s', // @translate
                            sprintf(
                                '<a href="%s">%s</a>',
                                htmlspecialchars($this->url()->fromRoute(null, [], true)),
                                $this->translate('Import another?')
                            ));
                        $message->setEscapeHtml(false);
                        $this->messenger()->addSuccess($message);
                        return $this->redirect()->toRoute('admin/diigo-import/default', ['action' => 'browse']);
                    } else {
                        $this->messenger()->addError(sprintf(
                            'Error when requesting Diigo library: %s%s', // @translate
                            $response->getReasonPhrase(),
                            $body['message']
                        ));
                    }
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }

    public function browseAction()
    {
        $this->setBrowseDefaults('id');
        $response = $this->api()->search('diigo_imports', $this->params()->fromQuery());
        $this->paginator($response->getTotalResults(), $this->params()->fromQuery('page'));

        $view = new ViewModel;
        $view->setVariable('imports', $response->getContent());
        return $view;
    }

    public function undoConfirmAction()
    {
        $import = $this->api()
            ->read('diigo_imports', $this->params('import-id'))->getContent();
        $form = $this->getForm(ConfirmForm::class);
        $form->setAttribute('action', $import->url('undo'));

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('diigo-import/index/undo-confirm');
        $view->setVariable('import', $import);
        $view->setVariable('form', $form);
        return $view;
    }

    public function undoAction()
    {
        if ($this->getRequest()->isPost()) {
            $import = $this->api()
                ->read('diigo_imports', $this->params('import-id'))->getContent();
            if (in_array($import->job()->status(), ['completed', 'stopped', 'error'])) {
                $form = $this->getForm(ConfirmForm::class);
                $form->setData($this->getRequest()->getPost());
                if ($form->isValid()) {
                    $args = ['import' => $import->id()];
                    $job = $this->jobDispatcher()->dispatch(Job\UndoImport::class, $args);
                    $this->api()->update('diigo_imports', $import->id(), [
                        'o-module-diigo_import:undo_job' => ['o:id' => $job->getId()],
                    ]);
                    $this->messenger()->addSuccess('Undoing Diigo import'); // @translate
                } else {
                    $this->messenger()->addFormErrors($form);
                }
            }
        }
        return $this->redirect()->toRoute(null, ['action' => 'browse'], true);
    }

    /**
     * Validate a Diigo API key.
     *
     * @param array $args
     * @return bool
     */
    protected function apiKeyIsValid(array $args)
    {
        $client = $this->client->resetParameters();
        $client->setAuth($args['login'], $args['pwd']);
        $url = new Url($args['apiKey'],$args['user']);
        $response = $client->setUri($url->items())->send();

        if (!$response->isSuccess()) {
            return false;
        }
        $bm = json_decode($response->getBody(), true);
        if (count($bm)) {
            // The user IDs match and the key has user library access.
            return true;
        }
        return false;
    }

    /**
     * Send a Diigo API request.
     *
     * @param array $args
     * @return Response
     */
    protected function sendApiRequest(array $args)
    {
        $client = $this->client->resetParameters();
        $client->setAuth($args['login'], $args['pwd']);
        $url = new Url($args['apiKey'],$args['user']);
        $params = ['start' => 0, 'count' => '2'];
        return $client->setUri($url->items($params))->send();
    }
}
