<?php

namespace KubernetesController;

use KubernetesController\Plugin\AbstractPlugin;

/**
 * Goals:
 *   cleanups only happen is config is valid/present (ie: do not attempt 'cleanups' if config is invalid or not loaded)
 *   cleanups only happen if feature is enabled (ie: it will not remove resource when a feature changes state from enabled -> disabled)
 *
 * Class Controller
 * @package KubernetesController
 */
class Controller
{
    /**
     * Controller ID
     *
     * @var string
     */
    private $controllerId;

    /**
     * Namespace of the controller ConfigMap
     *
     * @var string
     */
    private $configMapNamespace = 'kube-system';

    /**
     * Name of the controller ConfigMap
     *
     * @var string
     */
    private $configMapName;

    /**
     * Delay between failed invokeAction attempts on plugins
     *
     * @var int
     */
    private $failedActionWaitTime = 30;

    /**
     * kubernetes client
     *
     * @var \KubernetesClient\Client
     */
    public $kubernetesClient;

    /**
     * Name of the controller
     *
     * @var string
     */
    private $name;

    /**
     * Currently loaded plugins
     *
     * @var AbstractPlugin[]
     */
    private $plugins = [];

    /**
     * Class name of registered plugins (should be canonical paths starting with slashes (\) if using namespaces)
     *
     * @var string[]
     */
    private $registeredPlugins = [];

    /**
     * Provides access to arbitrary data/objects that can be used by plugins.  For example client classes etc.
     *
     * @var array
     */
    public $registry = [];

    /**
     * controller state
     *
     * @var array
     */
    public $state = [];

    /**
     * Controller/Plugin store
     *
     * @var Store
     */
    private $store;

    /**
     * Whether the store should be usable by plugins
     *
     * @var bool
     */
    private $storeEnabled = true;

    /**
     * Namespace of the store ConfigMap
     *
     * @var string
     */
    private $storeNamespace = 'kube-system';

    /**
     * Name of the store ConfigMap
     *
     * @var string
     */
    private $storeName;

    /**
     * Controller constructor.
     *
     * @param $name
     * @param \KubernetesClient\Client $kubernetesClient
     * @param array $options
     */
    public function __construct($name, \KubernetesClient\Client $kubernetesClient, $options = [])
    {
        $this->name = $name;
        $this->kubernetesClient = $kubernetesClient;

        if (isset($options['controllerId'])) {
            $this->controllerId = $options['controllerId'];
        }

        if (isset($options['configMapNamespace'])) {
            $this->configMapNamespace = $options['configMapNamespace'];
        }

        if (isset($options['configMapName'])) {
            $this->configMapName = $options['configMapName'];
        } else {
            $this->configMapName = $name.'-config';
        }

        if (isset($options['storeEnabled'])) {
            $this->storeEnabled = (bool) $options['storeEnabled'];
        }

        if (isset($options['storeNamespace'])) {
            $this->storeNamespace = $options['storeNamespace'];
        }

        if (isset($options['storeName'])) {
            $this->storeName = $options['storeName'];
        } else {
            $this->storeName = $name.'-store';
        }
    }

    /**
     * Registered plugins class names
     *
     * @param $className
     * @throws \Exception
     */
    public function registerPlugin($className)
    {
        if (!class_exists($className)) {
            throw new \Exception('cannot register unknown class as plugin: '.$className);
        }

        if (!is_subclass_of($className, '\KubernetesController\Plugin\AbstractPlugin')) {
            throw new \Exception('plugin must inherit from \KuberetesController\Plugin\AbstractPlugin: '.$className);
        }

        if (!in_array($className, $this->registeredPlugins)) {
            $this->registeredPlugins[] = $className;
        }
    }

    /**
     * Get the kubernetes client instance
     *
     * @return \KubernetesClient\Client
     */
    public function getKubernetesClient()
    {
        return $this->kubernetesClient;
    }

    /**
     * Controller ConfigMap namespace
     *
     * @return string
     */
    public function getConfigMapNamespace()
    {
        return $this->configMapNamespace;
    }

    /**
     * Controller ConfigMap name
     *
     * @return string
     */
    public function getConfigMapName()
    {
        return $this->configMapName;
    }

    /**
     * Store ConfigMap namespace
     *
     * @return string
     */
    public function getStoreNamespace()
    {
        return $this->storeNamespace;
    }

    /**
     * Store ConfigMap name
     *
     * @return string
     */
    public function getStoreName()
    {
        return $this->storeName;
    }

    /**
     * Get value from the store
     *
     * @param $key
     * @return mixed
     */
    public function getStoreValue($key)
    {
        return $this->store->get($key);
    }

    /**
     * Set value in the store
     *
     * @param $key
     * @param $value
     * @return bool
     */
    public function setStoreValue($key, $value)
    {
        return $this->store->set($key, $value);
    }

    /**
     * Get controller name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the state array for accessing stateful info in the plugins
     *
     * @return array
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Set registry item
     *
     * @param $key
     * @param $value
     */
    public function setRegistryItem($key, $value)
    {
        $this->registry[$key] = $value;
    }

    /**
     * Get registry item
     *
     * @param $key
     * @return mixed
     */
    public function getRegistryItem($key)
    {
        return $this->registry[$key];
    }

    /**
     * Log message to console
     *
     * @param $message
     */
    public function log($message)
    {
        echo date("c").' '.$message."\n";
    }

    /**
     * Log watch event message to console
     *
     * @param $event
     */
    public function logEvent($event)
    {
        $objectPath = '/'.$event['object']['apiVersion'];
        if (array_key_exists('namespace', $event['object']['metadata'])) {
            $objectPath .= '/namespaces/' . $event['object']['metadata']['namespace'];
        }
        $objectPath .= '/'. $event['object']['kind'];
        $objectPath .= '/'. $event['object']['metadata']['name'];

        $this->log($objectPath.' '.$event['type'].' - '.$event['object']['metadata']['resourceVersion']);
    }

    /**
     * Determine if the controller ConfigMap exists and has been loaded
     *
     * @return bool
     */
    private function getConfigLoaded()
    {
        return (bool) $this->state['config'];
    }

    /**
     * deinit and destroy all loaded plugins
     */
    private function stopPlugins()
    {
        foreach ($this->plugins as $plugin) {
            $plugin->deinit();
        }

        $this->plugins = [];
    }

    /**
     * Get the list of currently loaded/enabled plugins.  This is useful if plugins need to access each other.
     *
     * @return AbstractPlugin[]
     */
    public function getPlugins()
    {
        return $this->plugins;
    }

    /**
     * Get a laoded/enabled plugin by IDs
     *
     * @param $id
     * @return AbstractPlugin
     */
    public function getPluginById($id)
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin::getPluginId() == $id) {
                return $plugin;
            }
        }
    }

    /**
     * A user-supplied identifier used by the controller/plugins to identify resources belonging to this instance of the
     * controller.  It should be unique on a per-pfsense/per-cluster basis.
     *
     * @return string
     */
    public function getControllerId()
    {
        if (!empty($this->state['config']['controller-id'])) {
            return $this->state['config']['controller-id'];
        }

        return $this->controllerId;
    }

    /**
     * If store is enabled
     *
     * @return bool
     */
    public function getStoreEnabled()
    {
        return $this->storeEnabled;
    }

    /**
     * Crude method to test if resources created by the controller match this controller/cluster
     *
     * @param $name
     * @return bool
     */
    public function isManagedResource($name)
    {
        $needle = $this->getControllerId();
        $length = strlen($needle);
        return (substr($name, 0, $length) === $needle);
    }

    /**
     * Invoked when config is ADDED or MODIFIED.  Re-initializes plugins.
     */
    private function onConfigLoaded()
    {
        $this->log('controller config loaded/updated');
        $this->stopPlugins();
        if (!$this->state['config']['enabled']) {
            $this->log('controller disabled');
            return;
        }

        foreach ($this->state['config']['plugins'] as $pluginId => $config) {
            if (!$config['enabled']) {
                continue;
            }

            $this->log('loading plugin '.$pluginId);

            foreach ($this->registeredPlugins as $className) {
                if ($className::getPluginId() == $pluginId) {
                    $plugin = new $className($this);
                    $this->plugins[] = $plugin;
                    $plugin->init();
                    continue 2;
                }
            }

            $this->log("plugin ${pluginId} is not registered with the controller");
        }
    }

    /**
     * Invoked when config is DELETED.  Deletes plugins.
     */
    private function onConfigUnloaded()
    {
        $this->stopPlugins();
    }

    /**
     * Run loop for the controller
     *
     * @throws \Exception
     */
    public function main()
    {
        declare(ticks=1);
        pcntl_signal(SIGINT, function () {
            exit(0);
        });

        $kubernetesClient = $this->getKubernetesClient();
        $storeEnabled = $this->getStoreEnabled();

        if ($storeEnabled) {
            $this->store = new Store($this);
            $this->store->init();
        }

        $watches = new \KubernetesClient\WatchCollection();

        $configMapNamespace = $this->getConfigMapNamespace();
        $configMapName = $this->getConfigMapName();

        $storeNamespace = $this->getStoreNamespace();
        $storeName = $this->getStoreName();

        //controller config
        $watch = $kubernetesClient->createWatch("/api/v1/watch/namespaces/${configMapNamespace}/configmaps/${configMapName}", [], $this->getConfigMapWatchCallback());
        $watches->addWatch($watch);

        while (true) {
            usleep(100 * 1000);
            $watches->start(1);

            // do NOT perform anything until config is loaded
            if (!$this->getConfigLoaded()) {
                $this->log("waiting for ConfigMap ${configMapNamespace}/${configMapName} to be present and valid");
                sleep(5);
                continue;
            }

            // do NOT perform anything until store is ready
            if ($storeEnabled) {
                if (!$this->store->initialized()) {
                    $this->log("waiting for store ConfigMap ${storeNamespace}/${storeName} to be present and valid");
                    $this->store->init();
                    sleep(5);
                    continue;
                }
            }

            // update store data
            if ($storeEnabled) {
                $this->store->readWatches();
            }

            // process plugins
            foreach ($this->plugins as $plugin) {
                $plugin->preReadWatches();
                $plugin->readWatches();
                $plugin->postReadWatches();

                if ($plugin->getActionRequired()) {
                    // be patient in failure scenarios
                    if (!$plugin->getLastActionSuccess() && ((time() - $this->failedActionWaitTime) <= $plugin->getLastActionAttemptTime())) {
                        continue;
                    }

                    // wait for settle time
                    $settleTime = $plugin->getSettleTime();
                    $actionRequiredTime = $plugin->getActionRequiredTime();
                    if ($settleTime > 0 &&
                        $actionRequiredTime > 0 &&
                        ((time() - $actionRequiredTime) <= $settleTime)) {
                        continue;
                    }

                    // wait for throttle time
                    $throttleTime = $plugin->getThrottleTime();
                    $lastActionAttemptTime = $plugin->getLastActionAttemptTime();
                    if ($throttleTime > 0 &&
                        $lastActionAttemptTime > 0 &&
                        ((time() - $lastActionAttemptTime) <= $throttleTime)) {
                        continue;
                    }

                    $plugin->invokeAction();
                }
            }

            // update store data
            if ($storeEnabled) {
                $this->store->readWatches();
            }
        }
    }

    /**
     * The callback for the controller ConfigMap watch
     *
     * @return \Closure
     */
    private function getConfigMapWatchCallback()
    {
        return function ($event, $watch) {
            switch ($event['type']) {
                case 'ADDED':
                case 'MODIFIED':
                    $this->state['config'] = yaml_parse($event['object']['data']['config']);
                    $this->onConfigLoaded();
                    break;
                case 'DELETED':
                    $this->state['deleted_config'] = yaml_parse($event['object']['data']['config']);
                    $this->state['config'] = null;
                    $this->onConfigUnloaded();
                    break;
            }
        };
    }
}
