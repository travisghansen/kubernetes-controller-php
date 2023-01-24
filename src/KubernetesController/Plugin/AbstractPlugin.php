<?php

namespace KubernetesController\Plugin;

/**
 * All plugins should derive from this class.  It provides the basic needs for loops and other utilities.
 *
 * Class AbstractPlugin
 * @package KubernetesController\Plugin
 */
abstract class AbstractPlugin implements PluginInterface
{
    /**
     * Determine if action method should be invoked
     *
     * @var bool
     */
    private $actionRequired = false;

    /**
     * Time when actionRequired changed from false to true
     *
     * @var int
     */
    private $actionRequiredTime;

    /**
     * Controller instance
     *
     * @var \KubernetesController\Controller
     */
    private $controller;

    /**
     * If the last sync attempt was a success
     *
     * @var bool
     */
    private $lastActionSuccess = true;

    /**
     * Time of the last attempted action
     *
     * @var int
     */
    private $lastActionAttemptTime;

    /**
     * Time of the last successful action
     *
     * @var int
     */
    private $lastActionSuccessTime;

    /**
     * Time of the last failed action
     *
     * @var int
     */
    private $lastActionFailTime;

    /**
     * Plugin state
     *
     * @var array
     */
    public $state = [];

    /**
     * Stored watches
     *
     * @var \KubernetesClient\Watch[]
     */
    private $watches = [];

    /**
     * Create new instance of plugin
     *
     * AbstractPlugin constructor.
     * @param \KubernetesController\Controller $controller
     */
    public function __construct(\KubernetesController\Controller $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Used by the plugins to indicate doAction() should be invoked.  This is primarily useful for plugins that can
     * operate atomically based on cluster state.  ie: doAction() could be invoked repeatedly without harm or bad
     * side-effect.
     */
    final protected function delayedAction()
    {
        if ($this->actionRequired !== true) {
            $this->actionRequiredTime = time();
        }
        $this->actionRequired = true;
    }

    /**
     * Used by the controller to invoke action based on delayedAction being used
     *
     * @return bool
     */
    final public function invokeAction()
    {
        $time = time();
        $success = $this->doAction();
        $this->actionRequired = !$success;
        $this->lastActionSuccess = $success;
        $this->lastActionAttemptTime = $time;

        if ($success) {
            $this->lastActionSuccessTime = $time;
        } else {
            $this->lastActionFailTime = $time;
        }

        return $success;
    }

    /**
     * Get the ID of the registered plugin (IDs should be unique across all plugins)
     *
     * @return string
     */
    final public static function getPluginId()
    {
        return static::PLUGIN_ID;
    }

    /**
     * Log message to console
     *
     * @param $message
     */
    final protected function log($message)
    {
        $id = self::getPluginId();
        $this->getController()->log("plugin ({$id}): ".$message);
    }

    /**
     * Log a watch event to console
     *
     * @param $event
     */
    final protected function logEvent($event)
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
     * Get the config block for the plugin
     *
     * @return array
     */
    final protected function getConfig()
    {
        $id = self::getPluginId();
        return $this->getController()->state['config']['plugins'][$id];
    }

    /**
     * Determine if delayedAction has been invoked
     *
     * @return bool
     */
    final public function getActionRequired()
    {
        return $this->actionRequired;
    }

    /**
     * Time actionRequired moved from false to true
     *
     * @return int
     */
    final public function getActionRequiredTime()
    {
        return $this->actionRequiredTime;
    }

    /**
     * Get controller instance
     *
     * @return \KubernetesController\Controller
     */
    final protected function getController()
    {
        return $this->controller;
    }

    /**
     * If the last call of invokeAction was successful
     *
     * @return bool
     */
    final public function getLastActionSuccess()
    {
        return $this->lastActionSuccess;
    }

    /**
     * Time invokeAction was last called
     *
     * @return int
     */
    final public function getLastActionAttemptTime()
    {
        return $this->lastActionAttemptTime;
    }

    /**
     * Time invokeAction as last called successfully
     *
     * @return int
     */
    final public function getLastActionSuccessTime()
    {
        return $this->lastActionSuccessTime;
    }

    /**
     * Time invokeAction was last called unsuccessfully
     *
     * @return int
     */
    final public function getLastActionFailTime()
    {
        return $this->lastActionFailTime;
    }

    /**
     * Add watch
     *
     * @param \KubernetesClient\Watch $watch
     */
    final protected function addWatch(\KubernetesClient\Watch $watch)
    {
        $this->watches[] = $watch;
    }

    /**
     * Reads data from kubernetes API for all the watches
     *
     * @throws \Exception
     */
    final public function readWatches()
    {
        foreach ($this->watches as $watch) {
            $watch->start(1);
        }
    }

    /**
     * Get plugin persistent data
     *
     * @return array
     */
    final public function getStore()
    {
        return $this->getController()->getStoreValue(self::getPluginId());
    }

    /**
     * Save plugin persistent data
     *
     * @param $data
     * @return mixed
     */
    final protected function saveStore($data)
    {
        return $this->getController()->setStoreValue(self::getPluginId(), $data);
    }

    /**
     * If desired to use settle time override this method
     *
     * @return int
     */
    public function getSettleTime()
    {
        return 0;
    }

    /**
     * If desired to use throttle time override this method
     *
     * @return int
     */
    public function getThrottleTime()
    {
        return 0;
    }
}
