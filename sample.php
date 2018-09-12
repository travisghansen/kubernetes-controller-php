<?php
// be sure to apply the ConfigMap in deploy

require_once 'vendor/autoload.php';

class MyPlugin extends \KubernetesController\Plugin\AbstractPlugin
{
    const PLUGIN_ID = 'myplugin';
    public function init()
    {
        $controller = $this->getController();

        // initial load of nodes
        $nodes = $controller->getKubernetesClient()->request('/api/v1/nodes');
        $this->state['nodes'] = $nodes['items'];

        // watch for node changes
        $params = [
            'resourceVersion' => $nodes['metadata']['resourceVersion'],
        ];
        $watch = $controller->getKubernetesClient()->createWatch('/api/v1/watch/nodes', $params, $this->getNodeWatchCallback());
        $this->addWatch($watch);
    }

    public function deinit()
    {
    }

    public function preReadWatches()
    {
    }

    public function postReadWatches()
    {
    }

    public function doAction()
    {
        $event = $this->state['events']['latest'];
        $this->log($event['type'].' node '.$event['object']['metadata']['name']);
        $this->log(json_encode($this->getConfig()));
        $this->log($this->getController()->getRegistryItem('special'));
        return true;
    }

    private function getNodeWatchCallback()
    {
        return function ($event, $watch) {
            $this->state['events']['latest'] = $event;
            switch ($event['type']) {
                case 'ADDED':
                    $this->state['events']['latest_add'] = $event;
                    $this->delayedAction();
                    break;
                case 'DELETED':
                    $this->state['events']['latest_delete'] = $event;
                    $this->delayedAction();
                    break;
                case 'MODIFIED':
                    $this->state['events']['latest_modified'] = $event;
                    $this->delayedAction();
                    break;
            }
        };
    }
}

// this automatically loads using KUBCONFIG or ~/.kube/config
$config = KubernetesClient\Config::BuildConfigFromFile();
$kubernetesClient = new KubernetesClient\Client($config);
$controllerName = 'sample-controller';
$options = [
    //'controllerId' => 'some-controller-id',
    //'configMapNamespace' => 'kube-system',
    //'configMapName' => $controllerName.'-controller-config',
    //'storeEnabled' => true,
    //'storeNamespace' => 'kube-system',
    //'storeName' => $controllerName.'-controller-store',
];

$controller = new KubernetesController\Controller($controllerName, $kubernetesClient, $options);
$controller->setRegistryItem('special', 'sauce');
$controller->registerPlugin('\MyPlugin');
$controller->main();
