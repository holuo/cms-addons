<?php

declare (strict_types=1);

namespace think\addons;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use think\facade\Cache;
use think\facade\Config;

class Cloud
{
    /**
     * 定义单例模式的变量
     * @var null
     */
    private static $_instance = null;

    public static function getInstance()
    {
        if(empty(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * 验证用户信息
     * @param $user
     * @param $pass
     * @throws AddonsException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function chekcUser($user, $pass)
    {
        $this->getRequest(['url'=>'user/login', 'method'=>'POST', 'option'=>[
            'form_params' => [
                'username' => $user,
                'password' => $pass
            ]
        ]], function ($data) {
            Cache::set('cloud_token', $data['userinfo'],86400);
        });
    }

    /**
     * 获取列表
     * @param $filter
     * @return mixed
     */
    public function getList($filter)
    {
        return $this->getRequest(['url'=>'appcenter/getlist', 'method'=>'get', 'option'=>[
            'query' => $filter
        ]]);
    }

    /**
     * 获取筛选
     * @param $type
     * @return mixed
     */
    public function getFilter($type)
    {
        if (empty($type)) {
            throw new AddonsException('类型不能为空');
        }
        return $this->getRequest(['url'=>'appcenter/getfilter?type='.$type, 'method'=>'get']);
    }

    public function install($param)
    {
        $dir = runtime_path().'cloud'.DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        try {
            $client = $this->getClient();
            $response = $client->request('get', 'appcenter/download', ['query' => $param]);
            $content = $response->getBody()->getContents();
        }  catch (ClientException $exception) {
            throw new AddonsException($exception->getMessage());
        }

        if (substr($content, 0, 1) === '{') {
            // json 错误信息
            throw new AddonsException($json['msg']??'服务器返回数据异常~');
        }

        // 保存
        $zip = $dir.$param['name'].'.zip';
        if ($w = fopen($zip, 'w')) {
            fwrite($w, $content);
            fclose($w);

            // 解压
            exit('待定');
        }
        throw new AddonsException('没有权限保存【'.$zip.'】');
    }

    /**
     * 获取Client对象
     * @return Client
     */
    protected function getClient()
    {
        static $client;
        if (empty($client)) {
            $token = Cache::get('cloud_token');
            $token = !empty($token) && !empty($token['token']) ? $token['token'] : null;
            $client = new Client([
                'base_uri' => Config::get('cms.api_url'),
                'headers' => [
                    'token' => $token
                ]
            ]);
        }
        return $client;
    }

    /**
     * 通用请求
     * @param $option
     * @param callable $success
     * @return mixed
     */
    public function getRequest($option, callable $success=null)
    {
        try {
            $client = $this->getClient();
            $response = $client->request($option['method']??'post', $option['url'], $option['option']??[]);
            $content = $response->getBody()->getContents();
        }  catch (ClientException $exception) {
            throw new AddonsException($exception->getCode()==404 ? '404 Not Found' : $exception->getMessage());
        }

        $json = json_decode($content, true);
        if (!empty($json) && isset($json['code'])) {
            if ($json['code']==200) {
                if (empty($success)) {
                    return $json['data'];
                }
                return call_user_func($success, $json['data']);
            } else {
                throw new AddonsException($json['msg']);
            }
        } else {
            throw new AddonsException('返回的数据异常');
        }
    }
}