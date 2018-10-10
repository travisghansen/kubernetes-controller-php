<?php

namespace KubernetesController\Plugin;

/**
 * The Plugin Interface
 *
 * Interface PluginInterface
 * @package KubernetesController\Plugin
 */
interface PluginInterface
{
    /**
     * Called when the plugin is created.  May occur multiple times during the lifetime of the controller as plugins are
     * re-initialized if the controller config is updated in any way.  Typically used for setting up watches and/or
     * loading initial state of the plugin.
     *
     * @return void
     */
    public function init();

    /**
     * Called before destruction of the plugin.  May occur multiple times during the lifetime of the controller as
     * plugins are re-initialized if the controller config is updated in any way.
     *
     * @return void
     */
    public function deinit();

    /**
     * Invoked just before the watches are read
     *
     * @return void
     */
    public function preReadWatches();

    /**
     * Invoked just after the watches are read
     *
     * @return void
     */
    public function postReadWatches();

    /**
     * Invoked by the controller after delayedAction() has been invoked.  Note that if the action fails, the controller
     * will continue to attempt the action after configured time duration (Controller->$failedActionWaitTime). Generally
     * this is useful for idempotent actions that can be performed based on cluster state (no arguments are passed)
     * repeatedly without side-effect.  For example, ensuring that the configuration of an ingress controller wholly
     * matches cluster state.
     *
     * You may use the store to retain historical actions/data, or as a crude queue by adding and popping data when
     * appropriate.
     *
     * If you simply want to react to changes from watches you could do so directly in the callback and avoid using this
     * method and delayedAction() altogether.
     *
     * @return bool
     */
    public function doAction();

    /**
     * Time (in seconds) the plugin should wait after delayedAction has been invoked to trigger action.  This is helpful
     * to prevent too much flapping due to a flurry of k8s activity for plugins whose actions are idempotent.
     *
     * For example, if several k8s resources are deleted in rapid succession each triggering an action.  This allows the
     * plugin to wait for those events to calm down and then do a single update.
     *
     * Only applicable if > 0
     *
     * @return int
     */
    public function getSettleTime();

    /**
     * Time (in seconds) the plugin should wait between invocations of doAction.  This is helpful when actions are
     * idempotent and you want to keep activity down due to potentially high volume.
     *
     * Only applicable if > 0
     *
     * @return int
     */
    public function getThrottleTime();
}
