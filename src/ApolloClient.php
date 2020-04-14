<?php

namespace ElemenX\ApolloClient;

class ApolloClient
{
    protected $configServer; //apollo服务端地址
    protected $appId; //apollo配置项目的appid
    protected $cluster = 'default';
    protected $accessKeySecret = null;
    protected $clientIp = '127.0.0.1'; //绑定IP做灰度发布用
    protected $notifications = [];
    protected $pullTimeout = 10; //获取某个namespace配置的请求超时时间
    protected $intervalTimeout = 60; //每次请求获取apollo配置变更时的超时时间
    public $save_dir; //配置保存目录
    protected $modifyEnv;//是否自动修改 env 文件

    /**
     * ApolloClient constructor.
     * @param string $configServer apollo服务端地址
     * @param string $appId apollo配置项目的appid
     * @param array $namespaces apollo配置项目的namespace
     */
    public function __construct($configServer, $appId, array $namespaces)
    {
        $this->configServer = rtrim($configServer, '/');
        $this->appId = $appId;
        foreach ($namespaces as $namespace) {
            $this->notifications[$namespace] = ['namespaceName' => $namespace, 'notificationId' => -1];
        }
        $this->save_dir = dirname($_SERVER['SCRIPT_FILENAME']);
    }

    private function modifyEnv(array $data)
    {
        $envPath = base_path() . DIRECTORY_SEPARATOR . '.env';

        $contentArray = [];
        foreach ($data as $key => $value){
            $contentArray[] = $key . '=' . $value;
        }

        $content = implode("\n", $contentArray);

        file_put_contents($envPath, $content);
    }

    public function setModifyEnv($mode)
    {
        $this->modifyEnv = $mode;
    }

    private function isModifyEnv()
    {
        return $this->modifyEnv;
    }

    private function getSignedHeader($uri)
    {
        $timestamp = round(microtime(true) * 1000);

        return [
            'Authorization: Apollo ' . $this->appId . ':' . hash_hmac('sha1', $timestamp . "\n" . $uri, $this->accessKeySecret),
            'Timestamp: ' . $timestamp
        ];
    }

    public function setCluster($cluster)
    {
        $this->cluster = $cluster;
    }

    public function setClientIp($ip)
    {
        $this->clientIp = $ip;
    }

    public function setAccessKeySecret($accessKeySecret)
    {
        $this->accessKeySecret = $accessKeySecret;
    }

    public function setSaveDir($saveDir) {
        $this->save_dir = $saveDir;
    }

    public function setPullTimeout($pullTimeout) {
        $pullTimeout = intval($pullTimeout);
        if ($pullTimeout < 1 || $pullTimeout > 300) {
            return;
        }
        $this->pullTimeout = $pullTimeout;
    }

    public function setIntervalTimeout($intervalTimeout) {
        $intervalTimeout = intval($intervalTimeout);
        if ($intervalTimeout < 1 || $intervalTimeout > 300) {
            return;
        }
        $this->intervalTimeout = $intervalTimeout;
    }

    private function _getReleaseKey($config_file) {
        $releaseKey = '';
        if (file_exists($config_file)) {
            $last_config = require $config_file;
            is_array($last_config) && isset($last_config['releaseKey']) && $releaseKey = $last_config['releaseKey'];
        }
        return $releaseKey;
    }

    //获取单个namespace的配置文件路径
    public function getConfigFile($namespaceName) {
        return $this->save_dir.DIRECTORY_SEPARATOR.'apolloConfig.'.$namespaceName.'.php';
    }

    //获取单个namespace的配置-无缓存的方式
    public function pullConfig($namespaceName) {
        $request_uri = '/configs/'.$this->appId.'/'.$this->cluster.'/'.$namespaceName;

        $args = [];
        $args['ip'] = $this->clientIp;
        $config_file = $this->getConfigFile($namespaceName);
        $args['releaseKey'] = $this->_getReleaseKey($config_file);

        $request_uri .= '?' . http_build_query($args);

        $ch = curl_init($this->configServer.$request_uri);
        if (!is_null($this->accessKeySecret)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getSignedHeader($request_uri));
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->pullTimeout);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode == 200) {
            $result = json_decode($body, true);
            $content = '<?php return ' . var_export($result, true) . ';';
            file_put_contents($config_file, $content);

            if($namespaceName == 'application' and $this->isModifyEnv() and isset($result['configurations'])){
                $this->modifyEnv($result['configurations']);
            }
        }elseif ($httpCode != 304) {
            echo $body ?: $error."\n";
            return false;
        }
        return true;
    }

    //获取多个namespace的配置-无缓存的方式
    public function pullConfigBatch(array $namespaceNames) {
        if (! $namespaceNames) return [];
        $multi_ch = curl_multi_init();
        $request_list = [];
        $base_uri = '/configs/'.$this->appId.'/'.$this->cluster.'/';
        $query_args = [];
        $query_args['ip'] = $this->clientIp;
        foreach ($namespaceNames as $namespaceName) {
            $request = [];
            $config_file = $this->getConfigFile($namespaceName);
            $request_uri = $base_uri.$namespaceName;
            $query_args['releaseKey'] = $this->_getReleaseKey($config_file);
            $query_string = '?'.http_build_query($query_args);
            $request_uri .= $query_string;
            $ch = curl_init($this->configServer.$request_uri);
            if (!is_null($this->accessKeySecret)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getSignedHeader($request_uri));
            }
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->pullTimeout);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $request['ch'] = $ch;
            $request['config_file'] = $config_file;
            $request_list[$namespaceName] = $request;
            curl_multi_add_handle($multi_ch, $ch);
        }
        $active = null;
        do {
            $mrc = curl_multi_exec($multi_ch, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multi_ch) == -1) {
                usleep(100);
            }
            do {
                $mrc = curl_multi_exec($multi_ch, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            
        }

        // 获取结果
        $response_list = [];
        foreach ($request_list as $namespaceName => $req) {
            $response_list[$namespaceName] = true;
            $result = curl_multi_getcontent($req['ch']);
            $code = curl_getinfo($req['ch'], CURLINFO_HTTP_CODE);
            $error = curl_error($req['ch']);
            curl_multi_remove_handle($multi_ch,$req['ch']);
            curl_close($req['ch']);
            if ($code == 200) {
                $result = json_decode($result, true);
                $content = '<?php return '.var_export($result, true).';';
                file_put_contents($req['config_file'], $content);

                if($namespaceName == 'application' and $this->isModifyEnv() and isset($result['configurations'])){
                    $this->modifyEnv($result['configurations']);
                }
            }elseif ($code != 304) {
                echo 'pull config of namespace['.$namespaceName.'] error:'.($result ?: $error)."\n";
                $response_list[$namespaceName] = false;
            }
        }
        curl_multi_close($multi_ch);
        return $response_list;
    }

    protected function _listenChange(&$ch, $callback = null) {
        $base_uri = '/notifications/v2?';
        $params = [];
        $params['appId'] = $this->appId;
        $params['cluster'] = $this->cluster;
        do {
            $params['notifications'] = json_encode(array_values($this->notifications));
            $request_uri = $base_uri.http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $this->configServer.$request_uri);
            if (!is_null($this->accessKeySecret)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getSignedHeader($request_uri));
            }
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            if ($httpCode == 200) {
                $res = json_decode($response, true);
                $change_list = [];
                foreach ($res as $r) {
                    if ($r['notificationId'] != $this->notifications[$r['namespaceName']]['notificationId']) {
                        $change_list[$r['namespaceName']] = $r['notificationId'];
                    }
                }
                $response_list = $this->pullConfigBatch(array_keys($change_list));
                foreach ($response_list as $namespaceName => $result) {
                    $result && ($this->notifications[$namespaceName]['notificationId'] = $change_list[$namespaceName]);
                }
                //如果定义了配置变更的回调，比如重新整合配置，则执行回调
                ($callback instanceof \Closure) && call_user_func($callback);
            }elseif ($httpCode != 304) {
                throw new \Exception($response ?: $error);
            }
        }while (true);
    }

    /**
     * @param $callback 监听到配置变更时的回调处理
     * @return mixed
     */
    public function start($callback = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->intervalTimeout);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        try {
            $this->_listenChange($ch, $callback);
        }catch (\Exception $e) {
            curl_close($ch);
            return $e->getMessage();
        }
    }
}