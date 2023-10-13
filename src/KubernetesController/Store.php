<?php

namespace KubernetesController;

use KubernetesClient\Dotty\DotAccess;

/**
 * Used to provide persistent storage to the controller and plugins.  Should not be used directly by plugins but rather
 * plugins should use the appropriate methods on the plugin itself (setStore() and saveStore()) which automatically
 * isolates data for each plugin.
 *
 * Class Store
 * @package KubernetesController
 */
class Store
{
    /**
     * Controller instance
     *
     * @var Controller
     */
    private $controller;

    /**
     * Cached data from the backing store.  The ConfigMap is watched and any time changes occur this value is
     * automatically updated.
     *
     * @var array
     */
    private $data = [];

    /**
     * If the store has properly initialized
     *
     * @var bool
     */
    private $initialized = false;

    /**
     * Name of the kubernetes ConfigMap
     *
     * @var string
     */
    private $name;

    /**
     * Namespace of the kubernetes ConfigMap
     *
     * @var string
     */
    private $namespace;

    /**
     * Stored watches
     *
     * @var \KubernetesClient\Watch[]
     */
    private $watches = [];

    /**
     * Create new instance of store
     *
     * Store constructor.
     * @param Controller $controller
     */
    public function __construct(Controller $controller)
    {
        $this->controller = $controller;
        $this->namespace = $controller->getStoreNamespace();
        $this->name = $controller->getStoreName();
    }

    /**
     * Add watch to list
     *
     * @param \KubernetesClient\Watch $watch
     */
    private function addWatch(\KubernetesClient\Watch $watch)
    {
        $this->watches[] = $watch;
    }

    /**
     * Read watches.  Invoked by controller during the main loop.
     *
     * @throws \Exception
     */
    public function readWatches()
    {
        foreach ($this->watches as $watch) {
            $watch->start(1);
        }
    }

    /**
     * Initialize the store
     */
    public function init()
    {
        $kubernetesClient = $this->controller->getKubernetesClient();
        $storeNamespace = $this->namespace;
        $storeName = $this->name;

        // check for ConfigMap existence
        $response = $kubernetesClient->request("/api/v1/namespaces/{$storeNamespace}/configmaps/{$storeName}");
        if (DotAccess::get($response, 'status', null) == 'Failure') {
            // create ConfigMap
            $data = [
                'kind' => 'ConfigMap',
                'metadata' => [
                    'name' => $storeName,
                ],
                'data' => null,
            ];

            // check for success
            $response = $kubernetesClient->request("/api/v1/namespaces/{$storeNamespace}/configmaps", 'POST', [], $data);
            if (DotAccess::get($response, 'status', null) == 'Failure') {
                $this->controller->log(DotAccess::get($response, 'message'));
                return;
            }
        }

        // load initial data
        $data = DotAccess::get($response, 'data', null);
        if (!empty($data)) {
            if (is_array($data)) {
                array_walk($data, function (&$item, $key) {
                    $item = json_decode($item, true);
                });
            }

            if (is_object($data)) {
                foreach ($data as &$item) {
                    $item = json_decode($item, true);
                }
            }
        }
        $this->data = $data;

        // create watch
        $params = [
            'resourceVersion' => DotAccess::get($response, 'metadata.resourceVersion'),
        ];
        $watch = $kubernetesClient->createWatch("/api/v1/watch/namespaces/{$storeNamespace}/configmaps/{$storeName}", $params, $this->getConfigMapWatchCallback());
        $this->addWatch($watch);

        $this->initialized = true;
        $this->controller->log('store successfully initialized');
    }

    /**
     * If the store has properly initialized
     *
     * @return bool
     */
    public function initialized()
    {
        return $this->initialized;
    }

    /**
     * Callback used by the ConfimMap watch
     *
     * @return \Closure
     */
    private function getConfigMapWatchCallback()
    {
        return function ($event, $watch) {
            //$this->controller->logEvent($event);
            switch (DotAccess::get($event, 'type')) {
                case 'ADDED':
                case 'MODIFIED':
                    $data = DotAccess::get($event, 'object.data', null);
                    if (!empty(DotAccess::get($event, 'object.data'))) {
                        if (is_array($data)) {
                            array_walk($data, function (&$item, $key) {
                                $item = json_decode($item, true);
                            });
                        }

                        if (is_object($data)) {
                            foreach (DotAccess::get($event, 'object.data') as &$item) {
                                $item = json_decode($item, true);
                            }
                        }
                    }
                    $this->data = $data;
                    break;
                case 'DELETED':
                    $this->data = null;
                    break;
            }
        };
    }

    /**
     * Set store value
     *
     * @param $key
     * @param $value
     * @return bool
     */
    public function set($key, $value)
    {
        $kubernetesClient = $this->controller->getKubernetesClient();
        $storeNamespace = $this->namespace;
        $storeName = $this->name;

        $data = [
            'kind' => 'ConfigMap',
            'metadata' => [
                'name' => $storeName
            ],
            'data' => [
                $key => json_encode($value),
            ],
        ];

        $response = $kubernetesClient->request("/api/v1/namespaces/{$storeNamespace}/configmaps/{$storeName}", 'PATCH', [], $data);
        if (DotAccess::get($response, 'status', null) == 'Failure') {
            $this->controller->log(DotAccess::get($response, 'message'));

            return false;
        }

        return true;
    }

    /**
     * Get store value
     *
     * @param $key
     * @return mixed
     */
    public function get($key)
    {
        return DotAccess::get($this->data, $key,null);
    }
}
