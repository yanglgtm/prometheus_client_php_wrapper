prometheus 快速接入包
====================
[![Latest Stable Version](https://poser.pugx.org/imj/prometheus_client_php_wrapper/v/stable)](https://packagist.org/packages/imj/prometheus_client_php_wrapper)
[![License](https://poser.pugx.org/imj/prometheus_client_php_wrapper/license)](https://packagist.org/packages/imj/prometheus_client_php_wrapper)

Installation
------------
```shell
composer require imj/prometheus_client_php_wrapper
```

Basic Usage
------------

```php
require __DIR__ . '/../vendor/autoload.php';

// 初始化
PrometheusWrapper\Wrapper::ins()->init([
  "app" => "test",
  "idc" => "dev",
  "monitor_switch" => [
    PrometheusWrapper\Wrapper::METRIC_COUNTER_RESPONSES => ["/wrapperTest.php"],
    PrometheusWrapper\Wrapper::METRIC_COUNTER_SENT_BYTES => true, // 开启用于记录下游流量
    PrometheusWrapper\Wrapper::METRIC_COUNTER_REVD_BYTES => true,
    PrometheusWrapper\Wrapper::METRIC_HISTOGRAM_LATENCY => ["/wrapperTest.php"],
    PrometheusWrapper\Wrapper::METRIC_GAUGE_CONNECTS => true,
    PrometheusWrapper\Wrapper::METRIC_COUNTER_EXCEPTION => true,
  ],
  "log_method" => ["GET", "POST", "HEAD"], // method 过滤
  "buckets" => [1,2,3,4,5,6,7,8,9,10,11,13,15,17,19,22,25,28,32,36,41,47,54,62,71,81,92,105,120,137,156,178,203,231,263,299,340,387,440,500], // 桶距配置
  "adapter" => "redis", // apcu|apc|memory
  "redisOptions" => [
    'host' => '127.0.0.1',
    'auth' => "123456"
  ],
  "redisIns" => null // 也可以传入一个 redis 实例
]);

if (isset($_GET['clean'])) {
  // 清除统计数据
  PrometheusWrapper\Wrapper::ins()->flush();
}

// 自定义统计项
// histogram
PrometheusWrapper\Wrapper::ins()->latencyLog(rand(1, 20), "/get", "searcher", "GET"); // 延迟
// counter
PrometheusWrapper\Wrapper::ins()->qpsCounterLog(1, "/get", "searcher","GET", 200); // QPS
PrometheusWrapper\Wrapper::ins()->sendBytesCounterLog(1024, "/get", "searcher","GET", 200); // 流量 out
PrometheusWrapper\Wrapper::ins()->receiveBytesCounterLog(2048, "/get", "searcher","GET", 200); // 流量 in
PrometheusWrapper\Wrapper::ins()->exceptionLog(1, "mysql_connect_err"); // 异常
// gauge
PrometheusWrapper\Wrapper::ins()->gaugeLog("alive", "searcher");

// 统计页面
echo PrometheusWrapper\Wrapper::ins();

// 单独统计页面（未做 init 操作，设置 adapter 相关配置即可）
// echo PrometheusWrapper\Wrapper::ins()->setConfig(["adapter" => "redis", "redisOptions" => ['host' => '127.0.0.1', 'auth' => "123456"]]);
```

License
------------

licensed under the MIT License - see the `LICENSE` file for details
