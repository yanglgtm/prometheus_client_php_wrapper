<?php

namespace PrometheusWrapper\Handler;

use PrometheusWrapper\Wrapper;

abstract class AbstractHandler
{
    /**
     * @var Wrapper
     */
    protected $prometheusWrapper;

    public function setWrapper(Wrapper $wrapper)
    {
        $this->prometheusWrapper = $wrapper;
    }

    abstract public function startup();
    abstract public function shutdown();
}
