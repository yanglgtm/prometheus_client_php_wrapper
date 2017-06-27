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
    const METRIC_COUNTER_RESPONSES = 1; // QPS
    const METRIC_COUNTER_SENT_BYTES = 2; // 出口流量
    const METRIC_COUNTER_REVD_BYTES = 3; // 入口流量
    const METRIC_HISTOGRAM_LATENCY = 4; // 延迟
    const METRIC_GAUGE_CONNECTS = 5; // 状态
    const METRIC_COUNTER_EXCEPTION = 6; // 程序异常

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

    protected static $responseCode = 200;

    /**
     * @var array
     */
    protected $config = [
        "app" => "default",
        "idc" => "",
        "monitor_switch" => [
            self::METRIC_COUNTER_RESPONSES => [],
            self::METRIC_COUNTER_SENT_BYTES => [],
            self::METRIC_COUNTER_REVD_BYTES => [],
            self::METRIC_HISTOGRAM_LATENCY => [],
            self::METRIC_GAUGE_CONNECTS => false,
            self::METRIC_COUNTER_EXCEPTION => false,
        ],
        "log_method" => ["GET", "POST"],   // method 过滤
        "buckets" => [],        // 桶距配置
        "adapter" => "memory",
        "redisOptions" => [],
        "redisIns" => null
    ];

    protected $adapterMap = [
        "redis" => "Prometheus\\Storage\\Redis",
        "apc" => "Prometheus\\Storage\\APC",
        "apcu" => "Prometheus\\Storage\\APCu",
        "memory" => "Prometheus\\Storage\\InMemory"
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
                if ($k == "monitor_switch") {
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
            if (isset($this->config["monitor_switch"][$k])) {
                if ($v && is_array($v)) {
                    $v = $this->parseLogUri($v);
                }
                $this->config["monitor_switch"][$k] = $v;
            }
        }
        return $this;
    }

    /**
     * 解析 uri 配置
     * @param array $uriArr
     * @return array
     */
    protected function parseLogUri(array $uriArr)
    {
        $ret = [];
        foreach ($uriArr as $uri) {
            list($path, $params) = $this->parseUri($uri);
            $ret[] = [
                "uri" => $uri,
                "path" => $path,
                "params" => $params
            ];
        }
        return $ret;
    }

    /**
     * 检查当前请求 uri 是否需要记录
     * @param $requestUri
     * @param $uriConfArr
     * @return bool
     */
    protected function isLogUri($requestUri, $uriConfArr)
    {
        list($request_path, $request_params) = $this->parseUri($requestUri);
        foreach ($uriConfArr as $uriConf) {
            if ($uriConf["path"] == $request_path) {
                if (!$uriConf["params"]) {
                    return $uriConf["uri"];
                } else {
                    foreach ($uriConf["params"] as $v) {
                        if (!in_array($v, $request_params)) {
                            return false;
                        }
                    }
                    return $uriConf["uri"];
                }
            }
        }
        return false;
    }

    /**
     * @param $uri
     * @return array|bool
     */
    protected function parseUri($uri)
    {
        $uriArr = parse_url($uri);
        if (!isset($uriArr["path"])) {
            return false;
        }
        return [
            $uriArr["path"],
            isset($uriArr["query"]) ? explode("&", $uriArr["query"]) : []
        ];
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
        if ($this->config["monitor_switch"][self::METRIC_COUNTER_RESPONSES]) {
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

        // 流量 out
        if ($this->config["monitor_switch"][self::METRIC_COUNTER_SENT_BYTES]) {
            $this->metricsRegister[self::METRIC_COUNTER_SENT_BYTES] = [
                "type" => self::TYPE_INS_COUNTER,
                "ins" => $this->collectorRegistry->registerCounter(
                    $this->config["app"],
                    "module_sent_bytes",
                    "[{$this->config['idc']}] traffic out of /path",
                    ["app", "api", "module", "method", "code"]
                )
            ];
        }

        // 流量 in
        if ($this->config["monitor_switch"][self::METRIC_COUNTER_REVD_BYTES]) {
            $this->metricsRegister[self::METRIC_COUNTER_REVD_BYTES] = [
                "type" => self::TYPE_INS_COUNTER,
                "ins" => $this->collectorRegistry->registerCounter(
                    $this->config["app"],
                    "module_revd_bytes",
                    "[{$this->config['idc']}] traffic in of /path",
                    ["app", "api", "module", "method", "code"]
                )
            ];
        }

        // 异常
        if ($this->config["monitor_switch"][self::METRIC_COUNTER_EXCEPTION]) {
            $this->metricsRegister[self::METRIC_COUNTER_EXCEPTION] = [
                "type" => self::TYPE_INS_COUNTER,
                "ins" => $this->collectorRegistry->registerCounter(
                    $this->config["app"],
                    "module_exceptions",
                    "[{$this->config['idc']}] exception",
                    ["app", "exception", "module"]
                )
            ];
        }

        // 延迟
        if ($this->config["monitor_switch"][self::METRIC_HISTOGRAM_LATENCY] && $this->config["buckets"]) {
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

        // 状态
        if ($this->config["monitor_switch"][self::METRIC_GAUGE_CONNECTS]) {
            $this->metricsRegister[self::METRIC_GAUGE_CONNECTS] = [
                "type" => self::TYPE_INS_GAUGE,
                "ins" => $this->collectorRegistry->registerGauge(
                    $this->config["app"],
                    "module_connections",
                    "[{$this->config['idc']}] state",
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

        if (!isset($_SERVER["REQUEST_URI"]) || !isset($_SERVER["REQUEST_METHOD"])) {
            // todo err counter
            return false;
        }

        $method = isset($_SERVER["REQUEST_METHOD"]) ? $_SERVER["REQUEST_METHOD"] : "GET";
        if (!in_array($method, $this->config["log_method"])) {
            return false;
        }

        $module = "self";
        $code = $this->getResponseCode();

        foreach ($this->metricsRegister as $name => $item) {
            $monitorSwitch = $this->config["monitor_switch"][$name];
            if (is_bool($monitorSwitch)) {
                continue;
            }
            $api = false;
            if (is_array($monitorSwitch)) {
                $api = $this->isLogUri($_SERVER["REQUEST_URI"], $monitorSwitch);
            }
            if (!$api) {
                continue;
            }
            $value = false;
            $labels = false;
            switch($name) {
                case self::METRIC_COUNTER_RESPONSES:
                    $value =  1;
                    $labels = [$this->config["app"], $api, $module, $method, $code];
                    break;
                case self::METRIC_COUNTER_SENT_BYTES:
                case self::METRIC_COUNTER_REVD_BYTES:
                    $labels = [$this->config["app"], $api, $module, $method, $code];
                    $value = ""; // todo 流量
                    break;
                case self::METRIC_HISTOGRAM_LATENCY:
                    $value = round(($this->etime - $this->btime) * 1000);
                    $labels = [$this->config["app"], $api, $module, $method];
                    break;
                case self::METRIC_GAUGE_CONNECTS:
                    // todo 状态
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
        } elseif ($this->config["adapter"] == "apc" || $this->config["adapter"] == "apcu") {
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
     * 自定义 延迟 Latency Log
     * @param $time
     * @param $api
     * @param $module
     * @param $method
     * @return bool
     */
    public function latencyLog($time, $api, $module = "self", $method = "GET")
    {
        if (!$this->initted || !isset($this->metricsRegister[self::METRIC_HISTOGRAM_LATENCY])) {
            return false;
        }
        call_user_func_array(
            [
                $this->metricsRegister[self::METRIC_HISTOGRAM_LATENCY]["ins"],
                $this->callMap[$this->metricsRegister[self::METRIC_HISTOGRAM_LATENCY]["type"]]
            ],
            [$time, [$this->config["app"], $api, $module, $method]]
        );
        return true;
    }

    /**
     * 自定义 Counter Log
     * @param $type
     * @param $value
     * @param $api
     * @param $module
     * @param $method
     * @param $code
     * @return bool
     */
    protected function counterLog($type, $value, $api, $module, $method, $code)
    {
        if (!$this->initted || !isset($this->metricsRegister[$type])) {
            return false;
        }
        call_user_func_array(
            [$this->metricsRegister[$type]["ins"], $this->callMap[$this->metricsRegister[$type]["type"]]],
            [$value, [$this->config["app"], $api, $module, $method, $code]]
        );
        return true;
    }

    /**
     * 自定义 QPS Counter Log
     * @param $times
     * @param $api
     * @param $module
     * @param $method
     * @param $code
     * @return bool
     */
    public function qpsCounterLog($times, $api, $module = "self", $method = "GET", $code = 200)
    {
        return $this->counterLog(self::METRIC_COUNTER_RESPONSES, $times, $api, $module, $method, $code);
    }

    /**
     * 自定义 发送流量 Counter Log
     * @param $bytes
     * @param $api
     * @param $module
     * @param $method
     * @param $code
     * @return bool
     */
    public function sendBytesCounterLog($bytes, $api, $module = "self", $method = "GET", $code = 200)
    {
        return $this->counterLog(self::METRIC_COUNTER_SENT_BYTES, $bytes, $api, $module, $method, $code);
    }

    /**
     * 自定义 接收流量 Counter Log
     * @param $bytes
     * @param $api
     * @param $module
     * @param $method
     * @param $code
     * @return bool
     */
    public function receiveBytesCounterLog($bytes, $api, $module = "self", $method = "GET", $code = 200)
    {
        return $this->counterLog(self::METRIC_COUNTER_REVD_BYTES, $bytes, $api, $module, $method, $code);
    }

    /**
     * 自定义 异常 Counter Log
     * @param $times
     * @param $exception
     * @param string $module
     * @return bool
     */
    public function exceptionLog($times, $exception, $module = "self")
    {
        if (!$this->initted || !isset($this->metricsRegister[self::METRIC_COUNTER_EXCEPTION])) {
            return false;
        }
        call_user_func_array(
            [
                $this->metricsRegister[self::METRIC_COUNTER_EXCEPTION]["ins"],
                $this->callMap[$this->metricsRegister[self::METRIC_COUNTER_EXCEPTION]["type"]]
            ],
            [$times, [$this->config["app"], $exception, $module]]
        );
        return true;
    }

    /**
     * 自定义 状态 Gauge Log
     * @param $value
     * @param $state
     * @return bool
     */
    public function gaugeLog($value, $state)
    {
        if (!$this->initted || !isset($this->metricsRegister[self::METRIC_GAUGE_CONNECTS])) {
            return false;
        }
        call_user_func_array(
            [
                $this->metricsRegister[self::METRIC_GAUGE_CONNECTS]["ins"],
                $this->callMap[$this->metricsRegister[self::METRIC_GAUGE_CONNECTS]["type"]]
            ],
            [$value, [$this->config["app"], $state]]
        );
        return true;
    }

    /**
     * @return int
     */
    public function getResponseCode()
    {
        return self::$responseCode;
    }

    /**
     * 设置响应状态码（通常在php set response code 的时候做改操作，默认全为200）
     * @param $code
     */
    public static function setResponseCode($code)
    {
        self::$responseCode = (int)$code;
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
