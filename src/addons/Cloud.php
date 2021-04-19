<?php

declare (strict_types=1);

namespace think\addons;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use think\Exception;
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
     * 获取单个应用的详细信息
     * @param $filter
     * @return mixed
     * @throws AddonsException
     */
    public function getInfo($filter)
    {
        return $this->getRequest(['url'=>'appcenter/getinfo', 'method'=>'get', 'option'=>[
            'query' => $filter
        ]]);
    }

    /**
     * 获取多个应用信息
     * @param $names
     * @return mixed
     * @throws AddonsException
     */
    public function getInfos($names)
    {
        return $this->getRequest(['url'=>'appcenter/getinfos', 'method'=>'get', 'option'=>[
            'query' => $names
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
            throw new AddonsException(lang('Type cannot be empty'));
        }
        return $this->getRequest(['url'=>'appcenter/getfilter?type='.$type, 'method'=>'get']);
    }

    /**
     * 给定目录路径打包下载
     * @param $path
     * @throws AddonsException
     */
    public function pack($path)
    {
        $zipFile = new \PhpZip\ZipFile();
        try{
            $zipFile
                ->addDirRecursive($path) // 包含下级，递归
                //  ->saveAsFile(runtime_path().'a.zip') // 保存
                ->outputAsAttachment(md5($path).'.zip'); // 直接输出到浏览器
        } catch(Exception $e){
            $zipFile->close();
            throw new AddonsException($e->getMessage());
        } catch(\PhpZip\Exception\ZipException $e){
            $zipFile->close();
            throw new AddonsException($exception->getMessage());
        }
    }

    /**
     * 插件安装
     * @param $info
     * @param bool $update
     * @return bool
     * @throws AddonsException
     */
    public function install($info, $update=false)
    {
        // dir - 解压路径，addonsPath - 安装路径
        list($dir, $addonsPath, $staticPath) = $this->competence($info, $update);
        try {
            $client = $this->getClient();
            $response = $client->request('get', 'appcenter/download', ['query' => ['name'=>$info['name'], 'version'=>$info['version']['version'], 'cms_version'=>config('cms.cms_version')]]);
            $content = $response->getBody()->getContents();
        }  catch (ClientException $exception) {
            throw new AddonsException($exception->getMessage());
        }

        if (substr($content, 0, 1) === '{') {
            // json 错误信息
            $json = json_decode($content, true);
            throw new AddonsException($json['msg']??lang('Server returns abnormal data'));
        }

        // 保存
        $zip = $dir.$info['name'].'.zip';
        if ($w = fopen($zip, 'w')) {
            fwrite($w, $content);
            fclose($w);

            // 安装过程中创建的目录文件
            $installDirArr = [];

            $zipFile = new \PhpZip\ZipFile();
            try {
                $zipFile->openFile($zip);

                // 创建解压路径
                $unzipPath = $dir . $info['name'] . DIRECTORY_SEPARATOR;
                @mkdir($unzipPath);

                // 解压
                $zipFile->extractTo($unzipPath);
                $installDirArr[] = $unzipPath; // 出错后需要删除的目录

                // 检查info.ini文件
                $info_file = $unzipPath . 'info.ini';
                if (!is_file($info_file)) {
                    throw new AddonsException(lang('The info.ini file does not exist'));
                }
                $_info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
                if ('template'!=$info['type'] && (empty($_info) || empty($_info['name']) || empty($_info['type']))) {
                    throw new AddonsException(lang('The name and type in the info.ini file must be!'));
                } else if ('template'==$info['type'] && (empty($_info) || empty($_info['module']) || empty($_info['name']))) {
                    throw new AddonsException(lang('The module, name, and type in the info.ini file must be!'));
                }

                // 模板情况下的处理
                if ('template'==$info['type']) {
                    $staticAppPath = $staticPath . $info['name'] . DIRECTORY_SEPARATOR;
                    $addonsAppPath = $addonsPath . $info['name'] . DIRECTORY_SEPARATOR;

                    if ($update===false) { // 安装的情况下
                        @mkdir($staticAppPath, 0755, true);
                        @mkdir($addonsAppPath, 0755, true);

                        // 需要删除目录
                        $installDirArr[] = $staticAppPath;
                        $installDirArr[] = $addonsAppPath;
                    }

                    // 移动对应的模板目录
                    $dir = Dir::instance();
                    if (is_dir($unzipPath . 'static' . DIRECTORY_SEPARATOR)) {
                        $bl = $dir->movedFile($unzipPath . 'static' . DIRECTORY_SEPARATOR, $staticAppPath);
                        if ($bl===false) {
                            throw new AddonsException($dir->error);
                        }
                    }
                    $bl = $dir->movedFile($unzipPath, $addonsAppPath);
                    if ($bl===false) {
                        throw new AddonsException($dir->error);
                    }
                } else {
                    // 创建插件目录
                    @mkdir($addonsPath . $info['name'], 0755, true);
                    $installDirArr[] = $addonsPath . $info['name'] . DIRECTORY_SEPARATOR;
                    $zipFile->extractTo($addonsPath . $info['name'] . DIRECTORY_SEPARATOR);

                     if ($update===true && $info['status']==1) { // 判断是否已经启用，先禁用
                         $this->disable($info['name']);
                     }

                    $obj = get_addons_instance($info['name']);
                    if (!empty($obj)) { // 调用插件安装
                        $obj->install();
                    }

                    // 调用插件启用方法
                    $this->enable($info['name']);
                }
                $zipFile->close();
            } catch (AddonsException $e) {
                $zipFile->close();
                $this->clearInstallDir($installDirArr, [$zip]);
                throw new AddonsException($e->getMessage());
            } catch (\PhpZip\Exception\ZipException $e) {
                $zipFile->close();
                $this->clearInstallDir($installDirArr, [$zip]);
                throw new AddonsException($e->getMessage());
            } catch (Exception $e) {
                $zipFile->close();
                $this->clearInstallDir($installDirArr, [$zip]);
                throw new AddonsException($e->getMessage());
            }
            @unlink($zip);
            return true;
        }
        throw new AddonsException(lang('No permission to save').'【'.$zip.'】');
    }

    /**
     * 本地安装
     * @param $type
     * @param $file
     * @return array|false
     * @throws AddonsException
     */
    public function installLocal($type, $file)
    {
        $path = dirname($file).DIRECTORY_SEPARATOR;
        $filename = basename($file, '.zip');

        $zipFile = new \PhpZip\ZipFile();
        $installDirArr = [];
        try {
            $zipFile->openFile($file);
            // 解压路径
            $unzipPath = $path . $filename . DIRECTORY_SEPARATOR;
            @mkdir($unzipPath);
            $zipFile->extractTo($unzipPath);
            $installDirArr[] = $unzipPath;

            // 检查info.ini文件
            $info_file = $unzipPath . 'info.ini';
            if (!is_file($info_file)) {
                throw new AddonsException(lang('The info.ini file does not exist'));
            }
            $_info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
            if ('template'!=$type && (empty($_info) || empty($_info['name']) || empty($_info['type']))) {
                throw new AddonsException(lang('The name and type in the info.ini file must be!'));
            } else if ('template'==$type && (empty($_info) || empty($_info['module']) || empty($_info['name']))) {
                throw new AddonsException(lang('The module, name, and type in the info.ini file must be!'));
            }
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $_info['name'])) {
                throw new AddonsException(lang('Incorrect plug-in ID format'));
            }
            $all = get_addons_info_all($type);
            if (isset($all[$_info['name']])) {
                throw new AddonsException(lang('Plug in %s already exists.', [$_info['name']]));
            }
            list($dir, $addonsPath, $staticPath) = $this->competence(['name'=>$_info['name'], 'type'=>$type, 'module'=>$_info['module']??'']);

            // 模板情况下的处理
            if ('template'==$type) {
                $staticAppPath = $staticPath . $_info['name'] . DIRECTORY_SEPARATOR;
                $addonsAppPath = $addonsPath . $_info['name'] . DIRECTORY_SEPARATOR;

                @mkdir($addonsAppPath, 0755, true);
                $installDirArr[] = $addonsAppPath;

                // 移动对应的模板目录
                $dir = Dir::instance();
                if (is_dir($unzipPath . 'static' . DIRECTORY_SEPARATOR)) { // 静态文件目录
                    $bl = $dir->movedFile($unzipPath . 'static' . DIRECTORY_SEPARATOR, $staticAppPath, $file);
                    if ($bl===false) {
                        throw new AddonsException($dir->error);
                    }
                    @mkdir($staticAppPath, 0755, true);
                    $installDirArr[] = $staticAppPath;
                }
                $bl = $dir->movedFile($unzipPath, $addonsAppPath);
                if ($bl===false) {
                    throw new AddonsException($dir->error);
                }
            } else {
                // 创建插件目录
                @mkdir($addonsPath . $_info['name'], 0755, true);
                $installDirArr[] = $addonsPath . $_info['name'] . DIRECTORY_SEPARATOR;
                $zipFile->extractTo($addonsPath . $_info['name'] . DIRECTORY_SEPARATOR);

                $obj = get_addons_instance($_info['name']);
                if (!empty($obj)) { // 调用插件安装
                    $obj->install();
                }

                // 调用插件启用方法
                $this->enable($_info['name']);
            }
            $zipFile->close();
            @unlink($file);
        } catch (AddonsException $e) {
            $zipFile->close();
            $this->clearInstallDir($installDirArr, [$file]);
            throw new AddonsException($e->getMessage());
        } catch (\PhpZip\Exception\ZipException $e) {
            $zipFile->close();
            $this->clearInstallDir($installDirArr, [$file]);
            throw new AddonsException($e->getMessage());
        } catch (Exception $e) {
            $zipFile->close();
            $this->clearInstallDir($installDirArr, [$file]);
            throw new AddonsException($e->getMessage());
        }
        return $_info;
    }

    /**
     * 插件卸载
     * @param $info
     * @return bool
     */
    public function uninstall($info)
    {
        if ('template' == $info['type']) { // 模板卸载方式
            $addonsPath = config('cms.tpl_path').$info['module'].DIRECTORY_SEPARATOR;
            $staticPath = config('cms.tpl_static').$info['module'].DIRECTORY_SEPARATOR;
            Dir::instance()->delDir($addonsPath.$info['name']);
            Dir::instance()->delDir($staticPath.$info['name']);
            return true;
        } else {
            // 插件卸载
            $obj = get_addons_instance($info['name']);
            if (!empty($obj)) { // 调用插件卸载
                $obj->uninstall();
            }

            Dir::instance()->delDir(app()->addons->getAddonsPath().$info['name']);
            return true;
        }
    }

    /**
     * 插件启用
     * @param $name string 插件标识
     * @return bool
     * @throws AddonsException
     */
    public function enable($name)
    {
        $appPathInstall = app()->addons->getAddonsPath().$name.DIRECTORY_SEPARATOR.'install'.DIRECTORY_SEPARATOR;

        $installFile = [];
        $installDir = [];
        if (is_dir($appPathInstall)) { // 复制安装目录到指定文件夹
            $list = Dir::instance()->getList($appPathInstall);
            if (!empty($list)) {
                try {
                    foreach ($list as $key=>$value) {
                        if ('app'==$value || 'template'==$value) { // php 代码复制
                            $listArr = Dir::instance()->rglob($appPathInstall. $value . DIRECTORY_SEPARATOR . '*', GLOB_BRACE);
                            if (empty($listArr)) {
                                continue;
                            }
                            foreach ($listArr as $k=>$v) { // 判断文件是否存在，是否有写入权限
                                if (is_file($v)) {
                                    $newFile = str_replace($appPathInstall. $value . DIRECTORY_SEPARATOR,base_path(),$v);
                                    if (file_exists($newFile)) {
                                        throw new AddonsException(lang('%s,existed',[$newFile]));
                                    }
                                    $installFile[] = $newFile; // 记录安装的文件，出错回滚
                                } else if (is_dir($v) && !is_writable($v)) {
                                    throw new AddonsException(lang('%s,Not writable', $v));
                                }
                            }
                            $bl = Dir::instance()->copyDir($appPathInstall. $value . DIRECTORY_SEPARATOR, base_path());
                            if ($bl===false) {
                                throw new AddonsException(lang('%s copy to %s fails',[$appPathInstall. $value . DIRECTORY_SEPARATOR,base_path()]));
                            }
                        } else if ('static'==$value) { // 静态文件 代码复制
                            $listArr = Dir::instance()->rglob($appPathInstall. $value . DIRECTORY_SEPARATOR . '*', GLOB_BRACE);
                            if (empty($listArr)) {
                                continue;
                            }

                            $addonsStatic = public_path('static'.DIRECTORY_SEPARATOR.'addons');
                            if (!is_writable($addonsStatic)) {
                                throw new AddonsException(lang('%s,Not writable', [$addonsStatic]));
                            }
                            if (is_dir($addonsStatic.DIRECTORY_SEPARATOR.$name)) {
                                throw new AddonsException(lang('%s,existed', [$addonsStatic]));
                            }
                            if (!@mkdir($addonsStatic.DIRECTORY_SEPARATOR.$name)) {
                                throw new AddonsException(lang('Failed to create "%s" folder',[$addonsStatic.DIRECTORY_SEPARATOR.$name]));
                            }
                            $installDir[] = $addonsStatic.DIRECTORY_SEPARATOR.$name; // 记录安装的文件，出错回滚
                            $bl = Dir::instance()->copyDir($appPathInstall. $value . DIRECTORY_SEPARATOR, $addonsStatic.DIRECTORY_SEPARATOR.$name);
                            if ($bl===false) {
                                throw new AddonsException(lang('%s copy to %s fails',[$appPathInstall. $value . DIRECTORY_SEPARATOR,$addonsStatic.DIRECTORY_SEPARATOR.$name]));
                            }
                        }
                    }
                } catch (AddonsException $exception) {
                    $this->clearInstallDir($installDir,$installFile);
                    throw new AddonsException($exception->getMessage());
                } catch (\think\Exception $exception) {
                    $this->clearInstallDir($installDir,$installFile);
                    throw new AddonsException($exception->getMessage());
                }
            }
        }

        // 执行插件启用方法
        $obj = get_addons_instance($name);
        if (!empty($obj) && method_exists($obj,'enable')) {
            $obj->enable();
        }

        // 使用数据库代替
        // $res = set_addons_info($name, ['status'=>1]);
        // if ($res!==true) {
        //     $this->clearInstallDir($installDir,$installFile);
        //     throw new AddonsException($exception->getMessage());
        // }
        return true;
    }

    /**
     * 插件禁用
     * @param $name string 标识名称
     * @return bool
     * @throws AddonsException
     */
    public function disable($name)
    {
        $appPathInstall = app()->addons->getAddonsPath().$name.DIRECTORY_SEPARATOR.'install'.DIRECTORY_SEPARATOR;
        $dirArr = [];
        $fileArr = [];
        $static = [];
        if (is_dir($appPathInstall)) { // 找出已安装的目录，并判断权限
            $list = Dir::instance()->getList($appPathInstall);
            if (!empty($list)) {
                foreach ($list as $key=>$value) {
                    if ('app'==$value || 'template'==$value) { // php 代码复制
                        $listArr = Dir::instance()->rglob($appPathInstall. $value . DIRECTORY_SEPARATOR . '*', GLOB_BRACE);
                        if (empty($listArr)) {
                            continue;
                        }
                        foreach ($listArr as $k=>$v) { // 判断文件是否存在，是否有写入权限
                            if (is_file($v)) {
                                $newFile = str_replace($appPathInstall. $value . DIRECTORY_SEPARATOR,base_path(),$v);
                                if (!is_writable($newFile)) {
                                    throw new AddonsException(lang('%s,File has no permission to write',[$newFile]));
                                }
                                $fileArr[] = $newFile;
                            } else if (is_dir($v)) {
                                if (!is_writable($v)) {
                                    throw new AddonsException(lang('%s,File has no permission to write',[$v]));
                                }
                                $dirArr[] = str_replace($appPathInstall. $value . DIRECTORY_SEPARATOR,base_path(),$v);;
                            }
                        }
                    } else if ('static'==$value) { // 静态文件 代码复制
                        $listArr = Dir::instance()->rglob($appPathInstall. $value . DIRECTORY_SEPARATOR . '*', GLOB_BRACE);
                        if (empty($listArr)) {
                            continue;
                        }

                        $addonsStatic = public_path('static'.DIRECTORY_SEPARATOR.'addons');
                        if (!is_writable($addonsStatic)) {
                            throw new AddonsException(lang('%s,Not writable', [$addonsStatic]));
                        }
                        $static[] = $addonsStatic.$name.DIRECTORY_SEPARATOR;
                    }
                }
            }
        }

        if (!empty($fileArr)) {
            foreach ($fileArr as $key=>$value) {
                @unlink($value);
            }
        }
        if (!empty($dirArr)) {
            foreach ($dirArr as $key=>$value) {
                @rmdir($value);
            }
        }
        if (!empty($static)) {
            $this->clearInstallDir($static,[]);
        }

        // 执行插件禁用方法
        $obj = get_addons_instance($name);
        if (!empty($obj) && method_exists($obj,'disable')) {
            $obj->disable();
        }

        // 使用数据库代替
        //$res = set_addons_info($name, ['status'=>0]);
        //if ($res!==true) {
        //    $this->clearInstallDir($installDir,$installFile);
        //    throw new AddonsException($exception->getMessage());
        //}
        return true;
    }

    /**
     * 权限判断，与创建对应目录
     * @param $param
     * @param $update boolean true-更新 false-安装
     * @throws AddonsException
     */
    public function competence($param, $update=false)
    {
        // 创建cloud目录
        $dir = runtime_path().'cloud'.DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // 判断插件或模块
        $staticPath = '';
        if ($param['type']=='template') {
            $addonsPath = config('cms.tpl_path').$param['module'].DIRECTORY_SEPARATOR;
            $staticPath = config('cms.tpl_static').$param['module'].DIRECTORY_SEPARATOR;
            if (!is_dir($addonsPath)) { // 模板安装目录是否存在
                throw new AddonsException(lang('The template depends on the "%s" application and failed!',[$param['module']]));
            }
            if (!is_writable($addonsPath)) { // 模板安装目录是否可写
                throw new AddonsException(lang('%s,Not writable', [$addonsPath]));
            }

            if (!is_dir($staticPath)) { // 静态资源安装目录
                throw new AddonsException(lang('The static resource directory "%s" does not exist!',[$param['module']]));
            }
            if (!is_writable($staticPath)) { // 静态资源安装目录是否可写
                throw new AddonsException(lang('%s,Not writable', [$staticPath]));
            }

            if (is_dir($addonsPath.$param['name']) && $update===false) {
                throw new AddonsException(lang('The template installation directory "%s" already exists!',[$addonsPath.$param['name']]));
            }
            if (is_dir($staticPath.$param['name']) && $update===false) {
                throw new AddonsException(lang('The static file installation directory "%s" already exists!',[$staticPath.$param['name']]));
            }
        } else {
            // addons 目录权限检测
            $addonsPath = app()->addons->getAddonsPath();
            if (!is_writable($addonsPath)) {
                throw new AddonsException(lang('%s,Folder has no permission to write',[$addonsPath]));
            }

            // 判断插件目录已存在
            $dirArr = $this->getAddonsDir($addonsPath);
            if (in_array($param['name'], $dirArr)  && $update===false) {
                throw new AddonsException(lang('%s,existed',[$param['name']]));
            }
        }
        return [$dir,$addonsPath,$staticPath];
    }

    /**
     * 获取插件目录
     * @param $dir
     * @return array
     */
    private function getAddonsDir($dir)
    {
        $dirArray = [];
        if (false != ($handle = opendir ( $dir ))) {
            while ( false !== ($file=readdir($handle)) ) {
                if ($file != "." && $file != ".." && strpos($file,".")===false) {
                    $dirArray[] = $file;
                }
            }
            closedir($handle);
        }
        return $dirArray;
    }

    /**
     * 清理安装目录
     * @param $dirArr
     * @param $fileArr
     */
    private function clearInstallDir($dirArr, $fileArr)
    {
        foreach ($dirArr as $value) {
            Dir::instance()->delDir($value);
        }
        foreach ($fileArr as $value) {
            @unlink($value);
        }
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
        }  catch (\think\exception\ErrorException $exception) {
            throw new AddonsException($exception->getMessage(),500);
        }  catch (ClientException $exception) {
            throw new AddonsException($exception->getCode()==404 ? '404 Not Found' : $exception->getMessage(), 500);
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
            throw new AddonsException(lang('Server returns abnormal data'));
        }
    }
}