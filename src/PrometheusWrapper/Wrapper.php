<?php

namespace PrometheusWrapper;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Class Wrapper
 * @package PrometheusWrapper
 */
class Wrapper
{
    /**
     * 基础监控项
     */
    const METRIC_COUNTER_RESPONSES = 1;
    const METRIC_COUNTER_SENT_BYTES = 2;
    const METRIC_HISTOGRAM_LATENCY = 3;
    const METRIC_GAUGE_CONNECTS = 4;

    /**
     * 注册实例类型
     */
    const TYPE_INS_COUNTER = 1;
    const TYPE_INS_HISTOGRAM = 2;
    const TYPE_INS_GAUGE = 3;

    /**
     * @var array
     */
    protected $metricsRegister = [];

    /**
     * @var \Prometheus\CollectorRegistry
     */
    protected $collectorRegistry;

    /**
     * @var \Prometheus\Storage\Adapter
     */
    protected $adapter;

    protected $initted = false;
    protected $btime;
    protected $etime;

    /**
     * @var array
     */
    protected $config = [
        "app" => "default",
        "idc" => "",
        "counter_path" => [],   // 添加 counter 统计的 path
        "histogram_path" => [], // 添加 histogram 统计的 path
        "log_method" => [],     // method 过滤
        "buckets" => [],        // 桶距配置
        "adapter" => "memory",
        "redisOptions" => [],
        "redisIns" => null,
        "switch" => [
            self::METRIC_COUNTER_RESPONSES => true,
            self::METRIC_COUNTER_SENT_BYTES => true,
            self::METRIC_HISTOGRAM_LATENCY => true,
            self::METRIC_GAUGE_CONNECTS => true,
        ]
    ];

    protected $adapterMap = [
        "redis" => "Prometheus\\Storage\\Redis",
        "apc" => "Prometheus\\Storage\\APC",
        "memory" => "Prometheus\\Storage\\InMemory",
    ];

    protected $callMap = [
        self::TYPE_INS_COUNTER => "incBy",
        self::TYPE_INS_HISTOGRAM => "observe",
        self::TYPE_INS_GAUGE => "set"
    ];

    private function __construct()
    {
        $this->btime = microtime(true);
    }

    protected static $instance = null;

    public static function ins()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 注入配置
     * @param array $user_config
     * @return $this
     */
    public function setConfig(array $user_config)
    {
        foreach ($user_config as $k => $v) {
            if (isset($this->config[$k]) && gettype($this->config[$k]) == gettype($v)) {
                if ($k == "switch") {
                    $this->switchMetric($v);
                } else {
                    $this->config[$k] = $v;
                }
            }
        }

        if (!array_key_exists($this->config["adapter"], $this->adapterMap)) {
            throw new \InvalidArgumentException("Invalid adapter");
        }
        if ($this->config["adapter"] == "redis" && !$this->config["redisOptions"] && !$this->config["redisIns"]) {
            throw new \InvalidArgumentException("Please check redis config");
        }

        $class = $this->adapterMap[$this->config["adapter"]];
        if ($this->config["adapter"] == "redis") {
            $this->adapter = new $class($this->config["redisOptions"], $this->config["redisIns"]);
        } else {
            $this->adapter = new $class();
        }
        $this->collectorRegistry = new CollectorRegistry($this->adapter);

        $this->initted = true;
        return $this;
    }

    /**
     * 监控开关
     * @param array $c
     * @return $this
     */
    public function switchMetric(array $c)
    {
        foreach ($c as $k => $v) {
            if (isset($this->config["switch"][$k])) {
                $this->config["switch"][$k] = (bool)$v;
            }
        }
        return $this;
    }

    /**
     * 初始化
     * @param array $user_config
     */
    public function init(array $user_config = [])
    {
        if (!$this->initted && $user_config) {
            $this->setConfig($user_config);
        }

        // QPS
        if ($this->config["switch"][self::METRIC_COUNTER_RESPONSES]) {
            $this->metricsRegister[self::METRIC_COUNTER_RESPONSES] = [
                "type" => self::TYPE_INS_COUNTER,
                "ins" => $this->collectorRegistry->registerCounter(
                    $this->config["app"],
                    "module_responses",
                    "[{$this->config['idc']}] number of /path",
                    ["app", "api", "module", "method", "code"]
                )
            ];
        }

        // 流量
        if ($this->config["switch"][self::METRIC_COUNTER_SENT_BYTES]) {
            $this->metricsRegister[self::METRIC_COUNTER_SENT_BYTES] = [
                "type" => self::TYPE_INS_COUNTER,
                "ins" => $this->collectorRegistry->registerCounter(
                    $this->config["app"],
                    "module_sent_bytes",
                    "[{$this->config['idc']}] traffic of /path",
                    ["app", "api", "module", "method", "code"]
                )
            ];
        }

        // 延迟
        if ($this->config["switch"][self::METRIC_HISTOGRAM_LATENCY] && $this->config["buckets"]) {
            $this->metricsRegister[self::METRIC_HISTOGRAM_LATENCY] = [
                "type" => self::TYPE_INS_HISTOGRAM,
                "ins" => $this->collectorRegistry->registerHistogram(
                    $this->config["app"],
                    "response_duration_milliseconds",
                    "[{$this->config['idc']}] response latency",
                    ["app", "api", "module", "method"],
                    $this->config["buckets"]
                )
            ];
        }

        // todo 状态
        if ($this->config["switch"][self::METRIC_GAUGE_CONNECTS] && false) {
            $this->metricsRegister[self::METRIC_GAUGE_CONNECTS] = [
                "type" => self::TYPE_INS_GAUGE,
                "ins" => $this->collectorRegistry->registerGauge(
                    $this->config["app"],
                    "module_connections",
                    "[{$this->config['idc']}] number of http connections",
                    ["app", "state"]
                )
            ];
        }

        register_shutdown_function(function() {
            $this->etime = microtime(true);
            $this->finalLog();
        });
    }

    /**
     * 基础监控 Log
     * @return bool
     */
    protected function finalLog()
    {
        if (!$this->initted) {
            return false;
        }

        $errlog = false;
        $module = "self";
        $code = 200;
        if (!isset($_SERVER["REQUEST_URI"]) || !isset($_SERVER["REQUEST_METHOD"])) {
            $errlog = true;
        }
        $r = parse_url($_SERVER["REQUEST_URI"]);
        if (!isset($r["path"])) {
            $errlog = true;
        }

        if ($errlog) {
            // todo err counter
            return false;
        }

        $api = $r["path"];
        $method = $_SERVER["REQUEST_METHOD"];
        $apiInCounter = in_array($api, $this->config["counter_path"]);
        $apiInHistogram = in_array($api, $this->config["histogram_path"]);

        if (($apiInCounter || $apiInHistogram) && in_array($method, $this->config["log_method"])) {
            foreach ($this->metricsRegister as $name => $item) {
                $value = false;
                $labels = false;
                switch($name) {
                    case self::METRIC_COUNTER_RESPONSES:
                        if ($apiInCounter) {
                            $value =  1;
                            $labels = [$this->config["app"], $api, $module, $method, $code];
                        }
                        break;
                    case self::METRIC_COUNTER_SENT_BYTES:
                        if ($apiInCounter) {
                            $labels = [$this->config["app"], $api, $module, $method, $code];
                            $value = ""; // todo
                        }
                        break;
                    case self::METRIC_HISTOGRAM_LATENCY:
                        if ($apiInHistogram) {
                            $value = round(($this->etime - $this->btime) * 1000);
                            $labels = [$this->config["app"], $api, $module, $method];
                        }
                        break;
                    case self::METRIC_GAUGE_CONNECTS:
                    default:
                        break;
                }
                if ($value && $labels) {
                    call_user_func_array(
                        [$this->metricsRegister[$name]["ins"], $this->callMap[$this->metricsRegister[$name]["type"]]],
                        [$value, $labels]
                    );
                }
            }
        }

        return true;
    }

    /**
     * @param bool $ret
     * @return bool|string
     */
    public function metrics($ret = false)
    {
        if (!$this->initted) {
            return "";
        }
        $renderer = new RenderTextFormat();
        $result = $renderer->render($this->collectorRegistry->getMetricFamilySamples());

        header('Content-type: ' . RenderTextFormat::MIME_TYPE);
        if ($ret) {
            return $result;
        } else {
            echo $result;
            return true;
        }
    }

    /**
     * 清空存储
     * @return bool
     */
    public function flush()
    {
        if (!$this->initted) {
            return false;
        }
        if ($this->config["adapter"] == "redis") {
            $this->adapter->flushRedis();
        } elseif ($this->config["adapter"] == "apc") {
            $this->adapter->flushAPC();
        } elseif ($this->config["adapter"] == "memory") {
            $this->adapter->flushMemory();
        } else {
            return false;
        }
        return true;
    }

    /**
     * 原始的注册对象 可自定义操作
     * @param $name
     * @return bool|mixed
     */
    public function getRegisteredMetrics($name)
    {
        if (isset($this->metricsRegister[$name])) {
            return $this->metricsRegister[$name]["ins"];
        }
        return false;
    }

    /**
     * 自定义 Latency Log
     * @param $time
     * @param $module
     * @param $api
     * @param $method
     * @return bool
     */
    public function latencyLog($time, $module, $api, $method)
    {
        if (!$this->initted) {
            return false;
        }

        call_user_func_array(
            [$this->metricsRegister[self::METRIC_HISTOGRAM_LATENCY]["ins"], $this->callMap[$this->metricsRegister[self::METRIC_HISTOGRAM_LATENCY]["type"]]],
            [$time, [$this->config["app"], $api, $module, $method]]
        );
        return true;
    }

    /**
     * 自定义 Counter Log
     * @param $times
     * @param $module
     * @param $api
     * @param $method
     * @param $code
     * @return bool
     */
    public function counterLog($times, $module, $api, $method, $code)
    {
        if (!$this->initted) {
            return false;
        }
        call_user_func_array(
            [$this->metricsRegister[self::METRIC_COUNTER_RESPONSES]["ins"], $this->callMap[$this->metricsRegister[self::METRIC_COUNTER_RESPONSES]["type"]]],
            [$times, [$this->config["app"], $api, $module, $method, $code]]
        );
        return true;
    }

    /**
     * @return \Prometheus\Storage\Adapter
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * @return CollectorRegistry
     */
    public function getCollectorRegistry()
    {
        return $this->collectorRegistry;
    }

    /**
     * @return bool|string
     */
    public function __toString()
    {
        return $this->metrics(true);
    }
}
