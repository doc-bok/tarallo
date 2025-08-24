<?php

/**
 * Abstract class to implement singletons.
 */
abstract class Singleton
{
    /**
     * Return the instance based on the calling class.
     */
    final public static function getInstance()
    {
        static $instances = array();
        $calledClass = get_called_class();

        if (!isset($instances[$calledClass])) {
            $instances[$calledClass] = new $calledClass();
        }

        return $instances[$calledClass];
    }

    /**
     * Prevent construction / cloning.
     */
    protected function __construct() {}
    private function __clone() {}
}