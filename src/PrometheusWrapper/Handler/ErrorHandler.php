<?php

namespace PrometheusWrapper\Handler;

class ErrorHandler extends AbstractHandler
{
    public function startup()
    {
        set_error_handler([$this, "errorHandler"]);
        set_exception_handler([$this, "exceptionHandler"]);
    }

    public function errorHandler($level, $message, $file, $line)
    {
        if (!$this->prometheusWrapper) {
            return false;
        }

        switch ($level) {
            case E_NOTICE:
            case E_USER_NOTICE:
                $log_level = 'php_notice';
                break;
            case E_WARNING:
            case E_USER_WARNING:
            case E_COMPILE_WARNING:
            case E_CORE_WARNING:
                $log_level = 'php_warning';
                break;
            case E_ERROR:
            case E_USER_ERROR:
            case E_COMPILE_ERROR:
            case E_CORE_ERROR:
            case E_RECOVERABLE_ERROR:
            case E_PARSE:
                $log_level = 'php_error';
                break;
            case E_STRICT:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $log_level = 'php_info';
                break;
            // 其他末知错误
            default:
                $log_level = 'php_error';
                break;
        }

        $this->prometheusWrapper->exceptionLog(1, $log_level);

        // 不影响PHP标准的错误处理程序
        return false;
    }

    /**
     * 捕捉异常
     * @param $e
     */
    public function exceptionHandler($e)
    {
        if ($this->prometheusWrapper) {
            $this->prometheusWrapper->exceptionLog(1, "php_exception");
        }
        throw $e;
    }

    /**
     * 捕捉致命错误
     * @return bool
     */
    public function shutdown()
    {
        if (!$this->prometheusWrapper) {
            return false;
        }

        $e = error_get_last();
        switch ($e['type']) {
            case E_ERROR:
            case E_PARSE:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                $this->errorHandler($e['type'], $e['message'], $e['file'], $e['line']);
                break;
            default:
                break;
        }
        return true;
    }
}
