<?php

declare (strict_types=1);

namespace think\addons;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PhpZip\Util\Iterator\IgnoreFilesRecursiveFilterIterator;
use think\Exception;
use think\facade\Cache;
use think\facade\Config;
use think\helper\Str;

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
     * 检测更新
     * @return mixed
     * @throws AddonsException
     */
    public function checkUpgrade($v, $p)
    {
        return $this->getRequest(['url'=>'cms/upgrade', 'method'=>'GET', 'option'=>[
            'query'=>['v'=>$v,'p'=>$p, 'type'=>2, 'domain'=>request()->host(), 'ip'=>request()->ip(), 'version'=>$v]
        ]]);
    }

    /**
     * 联盟授权检测
     * @param $domain
     * @return mixed
     */
    public function checkAuthorize($domain)
    {
        return $this->getRequest(['url'=>'cms/authorize', 'method'=>'GET', 'option'=>[
            'query'=>['domain'=>$domain]
        ]]);
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
            'query'=>$filter
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
     * 更新CMS
     * @param $v
     * @param string $p
     * @return bool
     */
    public function upgradeCms($v, $p = '')
    {
        try {
            $client = $this->getClient();
            $response = $client->request('get', 'cms/download', ['query' => ['v'=>$v, 'p'=>$p]]);
            $content = $response->getBody()->getContents();
        }  catch (ClientException $exception) {
            throw new AddonsException($exception->getMessage());
        }

        if (substr($content, 0, 1) === '{') {
            // json 错误信息
            $json = json_decode($content, true);
            throw new AddonsException($json['msg']??lang('Server returns abnormal data'));
        }

        // 保存路径
        $name = $v.'_'.$p;
        $zip = $this->getCloudTmp().$name.'.zip';
        if (file_exists($zip)) {
            @unlink($zip);
        }

        if ($w = fopen($zip, 'w')) {
            fwrite($w, $content);
            fclose($w);

            $dir = Dir::instance();
            try {
                // 解压
                $unzipPath = $this->unzip($name);

                // 备份cms
                $ignoreFiles = ['runtime/admin','runtime/cache','runtime/index','runtime/install','runtime/session','runtime/storage','.git','.idea']; // 忽略
                $backup = runtime_path().'backup'.DIRECTORY_SEPARATOR;
                @mkdir($backup);
                $backupZip = $backup.config('ver.cms_version').'.zip';
                $ignoreIterator = new IgnoreFilesRecursiveFilterIterator(new \RecursiveDirectoryIterator(root_path()),$ignoreFiles);
                (new \PhpZip\ZipFile())
                    ->addFilesFromIterator($ignoreIterator) // 包含下级，递归
                    ->saveAsFile($backupZip)
                    ->close();

                if (is_file($unzipPath.'upgrade.sql')) {
                    $this->exportSql($backup.config('ver.cms_version').'.sql');
                    create_sql($unzipPath.'upgrade.sql');
                }

                // 移动文件，解压目录移动到addons
                $dir->movedFile($unzipPath, root_path());
                // 清理
                $this->clearInstallDir([],[$zip]);
            } catch (\Exception $exception) {
                $this->clearInstallDir([$this->getCloudTmp().$name.DIRECTORY_SEPARATOR],[$zip]);
                throw new AddonsException($exception->getMessage());
            }
            return true;
        }
        throw new AddonsException(lang('No permission to save').'【'.$zip.'】');
    }

    /**
     * 给定目录路径打包下载
     * @param $path
     * @param string $savePath 为空-直接输出到浏览器，路径-保存到路径
     * @throws AddonsException
     */
    public function pack($path, $savePath = '')
    {
        $zipFile = new \PhpZip\ZipFile();
        try{
            if ($savePath) {
                $dir = runtime_path().'backup'.DIRECTORY_SEPARATOR;
                @mkdir($dir);
                $zipFile
                    ->addDirRecursive($path) // 包含下级，递归
                    ->saveAsFile($savePath)
                    ->close();
            } else {
                $zipFile
                    ->addDirRecursive($path) // 包含下级，递归
                    ->outputAsAttachment(md5($path).'.zip'); // 直接输出到浏览器
            }
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
     * 【name：插件标识，version：插件版本信息(array)，type: 应用类型,module:所属模块】
     * ['name'=>'demo','version'=>['verison'=>'1.0.0'],'type'=>'template','module'=>'index']
     * @param array $info 安装的插件信息
     * @return bool
     * @throws AddonsException
     */
    public function install($info)
    {
        // 目录权限检测
        $this->competence($info['type'], $info['name'], $info['module']);

        // 需要删除目录
        $installDirArr = [];
        $downloadPath = '';
        try {
            // 下载插件
            $downloadPath = $this->download($info['name'], $info['version']['version']);
            // 解压
            $unzipPath = $this->unzip($info['name']);
            // 验证应用必要文件与info.ini文件
            $this->checkIni($info['type'], $unzipPath);

            $dir = Dir::instance();
            if ($info['type']=='template') {
                list($templatePath, $staticPath) = $this->getTemplatePath($info['module']);
                $staticAppPath = $staticPath . $info['name'] . DIRECTORY_SEPARATOR;  // 模板静态安装路径
                $templatePath = $templatePath . $info['name'] . DIRECTORY_SEPARATOR; // 模板路径

                // 创建安装路径
                @mkdir($staticAppPath, 0755, true);
                @mkdir($templatePath, 0755, true);
                // 记录需要删除目录
                $installDirArr[] = $staticAppPath;
                $installDirArr[] = $templatePath;

                if (is_dir($unzipPath . 'static' . DIRECTORY_SEPARATOR)) { // 有模板静态资源的情况移动到public/static/module
                    $bl = $dir->movedFile($unzipPath . 'static' . DIRECTORY_SEPARATOR, $staticAppPath);
                    if ($bl===false) {
                        throw new AddonsException($dir->error);
                    }
                }
                $bl = $dir->movedFile($unzipPath, $templatePath); // 移动到模板目录下
                if ($bl===false) {
                    throw new AddonsException($dir->error);
                }
            } else { // 插件、模块
                $addonsPath = app()->addons->getAddonsPath(); // 插件根目录

                // 创建目录
                @mkdir($addonsPath . $info['name'], 0755, true);
                $installDirArr[] = $addonsPath . $info['name'] . DIRECTORY_SEPARATOR;

                // 移动文件，解压目录移动到addons
                $dir->movedFile($unzipPath,$addonsPath . $info['name'] . DIRECTORY_SEPARATOR);

                $obj = get_addons_instance($info['name']);
                if (!empty($obj)) { // 调用插件安装
                    $obj->install();
                }

                // 导入数据库
                $this->importSql($info['name']);

                // 调用插件启用方法
                $this->enable($info['name']);
            }

        } catch(AddonsException $e) {
            $this->clearInstallDir($installDirArr,[$downloadPath]);
            throw new AddonsException($e->getMessage());
        } catch (Exception $e) {
            $this->clearInstallDir($installDirArr,[$downloadPath]);
            throw new AddonsException($e->getMessage());
        }
        return true;
    }

    /**
     * 插件更新
     * @param $info
     * @return bool
     */
    public function upgrade($info)
    {
        // 目录权限检测
        $this->competence($info['type'], $info['name'], $info['module'], true);

        // 需要删除目录
        $installDirArr = [];
        $downloadPath = '';
        try {
            // 下载插件
            $downloadPath = $this->download($info['name'], $info['version']['version']);
            // 解压
            $unzipPath = $this->unzip($info['name']);
            // 验证应用必要文件与info.ini文件
            $this->checkIni($info['type'], $unzipPath);
            $dir = Dir::instance();

            if ($info['type']=='template') {
                list($templatePath, $staticPath) = $this->getTemplatePath($info['module']);
                $staticAppPath = $staticPath . $info['name'] . DIRECTORY_SEPARATOR;  // 模板静态安装路径
                $templatePath = $templatePath . $info['name'] . DIRECTORY_SEPARATOR; // 模板路径

                if (is_dir($unzipPath . 'static' . DIRECTORY_SEPARATOR)) { // 有模板静态资源的情况移动到public/static/module
                    $bl = $dir->movedFile($unzipPath . 'static' . DIRECTORY_SEPARATOR, $staticAppPath);
                    if ($bl===false) {
                        throw new AddonsException($dir->error);
                    }
                }
                $bl = $dir->movedFile($unzipPath, $templatePath); // 移动到模板目录下
                if ($bl===false) {
                    throw new AddonsException($dir->error);
                }
            } else {
                $addonsPath = app()->addons->getAddonsPath(); // 插件根目录

                if ($info['status']==1) { // 判断是否已经启用，先禁用
                    $this->disable($info['name']);
                }

                // 移动文件，解压目录移动到addons
                $dir->movedFile($unzipPath,$addonsPath . $info['name'] . DIRECTORY_SEPARATOR);

                $obj = get_addons_instance($info['name']);
                if (!empty($obj) && method_exists($obj,'upgrade')) { // 调用插件更新
                    $obj->upgrade();
                }

                // 导入数据库
                $this->importSql($info['name']);

                // 调用插件启用方法
                $this->enable($info['name']);
            }
        } catch(AddonsException $e) {
            $this->clearInstallDir($installDirArr,[$downloadPath]);
            throw new AddonsException($e->getMessage());
        } catch (Exception $e) {
            $this->clearInstallDir($installDirArr,[$downloadPath]);
            throw new AddonsException($e->getMessage());
        }
        return true;
    }

    /**
     * 本地安装
     * @param string $type 应用类型
     * @param string $file zip压缩位置
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
            $_info = $this->checkIni($type, $unzipPath);
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $_info['name'])) {
                throw new AddonsException(lang('Incorrect plug-in ID format'));
            }
            $all = get_addons_info_all($type);
            if (isset($all[$_info['name']])) {
                throw new AddonsException(lang('Plug in %s already exists.', [$_info['name']]));
            }
            $this->competence($type,$_info['name'],$_info['module']??'');

            // 模板情况下的处理
            if ('template'==$type) {
                list($templatePath, $staticPath) = $this->getTemplatePath($_info['module']);
                $staticAppPath = $staticPath . $_info['name'] . DIRECTORY_SEPARATOR;
                $addonsAppPath = $templatePath . $_info['name'] . DIRECTORY_SEPARATOR;

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
                $addonsPath = app()->addons->getAddonsPath(); // 插件根目录
                @mkdir($addonsPath . $_info['name'], 0755, true);
                $installDirArr[] = $addonsPath . $_info['name'] . DIRECTORY_SEPARATOR;
                $zipFile->extractTo($addonsPath . $_info['name'] . DIRECTORY_SEPARATOR);

                $obj = get_addons_instance($_info['name']);
                if (!empty($obj)) { // 调用插件安装
                    $obj->install();
                }

                // 导入数据库
                $this->importSql($_info['name']);

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
        // 插件install文件夹路径
        $installPath = app()->addons->getAddonsPath().$name.DIRECTORY_SEPARATOR.'install'.DIRECTORY_SEPARATOR;

        // 检查安装目录是否有覆盖的文件，并复制文件
        if (is_dir($installPath)) {
            $installFile = [];
            $installDir = [];
            try {
                $list = ['app','public','template','static'];
                foreach ($list as $key=>$value) {
                    // 当前插件文件夹/install/app(template、static)
                    $installPathDir = $installPath. $value . DIRECTORY_SEPARATOR;
                    if (!is_dir($installPathDir)) {
                        continue;
                    }

                    if ('app'==$value || 'public'==$value) { // php 代码复制

                        // 获取安装的目录文件是否存在
                        $listArr = Dir::instance()->rglob($installPathDir . '*', GLOB_BRACE);
                        if (empty($listArr)) {
                            continue;
                        }

                        // 判断是否已经存在该文件了，存在就报错
                        $tmpFiles = [];
                        foreach ($listArr as $k=>$v) {
                            $newFile = str_replace($installPathDir, base_path(), $v);
                            if (is_file($v) && file_exists($newFile)) {
                                $tmpFiles[] = $newFile;
                            }
                        }
                        if (!empty($tmpFiles)) {
                            $tmpFiles = implode(',', $tmpFiles);
                            throw new AddonsException(lang('%s,existed',[$tmpFiles]));
                        }

                        // 复制目录
                        $bl = Dir::instance()->copyDir($installPathDir, base_path());
                        if ($bl===false) {
                            throw new AddonsException(lang('%s copy to %s fails',[$installPathDir,base_path()]));
                        }
                    } else if ('template'==$value) { // 复制到模板
                        $listArr = Dir::instance()->getList($installPathDir);
                        $site = site();

                        foreach ($listArr as $k=>$v) {
                            if (in_array($v,['.','..']) || !isset($site[$v.'_theme'])) { // 必须是模块文件夹
                                continue;
                            }

                            $themePath = root_path('template') . $v . DIRECTORY_SEPARATOR . $site[$v.'_theme'] . DIRECTORY_SEPARATOR;

                            // 获取插件安装文件模块目录下的所有文件
                            $temp_installPathDir = $installPathDir . $v . DIRECTORY_SEPARATOR;

                            // 判断是否已经存在该文件
                            $temp = Dir::instance()->rglob( $temp_installPathDir . '*', GLOB_BRACE);
                            if (empty($temp)) {
                                continue;
                            }
                            $tmpFiles = [];
                            foreach ($temp as $item) {
                                $newFile = str_replace($temp_installPathDir, $themePath, $item);
                                if (is_file($item) && file_exists($newFile)) {
                                    $tmpFiles[] = $newFile; // 记录已存在的文件
                                }
                            }
                            if (!empty($tmpFiles)) {
                                $tmpFiles = implode(',', $tmpFiles);// 报错已存在的文件
                                throw new AddonsException(lang('%s,existed',[$tmpFiles]));
                            }

                            // 复制目录
                            $bl = Dir::instance()->copyDir($temp_installPathDir, $themePath);
                            if ($bl===false) {
                                throw new AddonsException(lang('%s copy to %s fails',[$temp_installPathDir,$themePath]));
                            }
                        }
                    } else if ('static'==$value) { // 静态文件 代码复制
                        $listArr = Dir::instance()->rglob($installPathDir . '*', GLOB_BRACE);
                        if (empty($listArr)) {
                            continue;
                        }
                        $addonsStatic = public_path('static'.DIRECTORY_SEPARATOR.'addons');
                        if (is_dir($addonsStatic.DIRECTORY_SEPARATOR.$name)) {
                            throw new AddonsException(lang('%s,existed', [$addonsStatic.DIRECTORY_SEPARATOR.$name]));
                        }
                        if (!@mkdir($addonsStatic.DIRECTORY_SEPARATOR.$name)) {
                            throw new AddonsException(lang('Failed to create "%s" folder',[$addonsStatic.DIRECTORY_SEPARATOR.$name]));
                        }
                        $installDir[] = $addonsStatic.DIRECTORY_SEPARATOR.$name; // 记录安装的文件，出错回滚
                        $bl = Dir::instance()->copyDir($installPathDir, $addonsStatic.DIRECTORY_SEPARATOR.$name);
                        if ($bl===false) {
                            throw new AddonsException(lang('%s copy to %s fails',[$installPathDir,$addonsStatic.DIRECTORY_SEPARATOR.$name]));
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

        // 执行插件启用方法
        $obj = get_addons_instance($name);
        if (!empty($obj) && method_exists($obj,'enable')) {
            $obj->enable();
        }
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
        $installPath = app()->addons->getAddonsPath().$name.DIRECTORY_SEPARATOR.'install'.DIRECTORY_SEPARATOR;

        $dirArr = []; // 文件夹
        $fileArr = []; // 文件列表
        $static = []; // 静态资源
        if (is_dir($installPath)) { // 找出已安装的
            $list = ['app','public','template','static'];
            foreach ($list as $key=>$value) {
                $installPathDir = $installPath. $value . DIRECTORY_SEPARATOR;
                if (!is_dir($installPathDir)) {
                    continue;
                }

                if ('app'==$value || 'public'==$value) { // php 代码复制
                    $listArr = Dir::instance()->rglob($installPathDir . '*', GLOB_BRACE);
                    if (empty($listArr)) {
                        continue;
                    }
                    foreach ($listArr as $k=>$v) { // 找出已经存在的文件
                        if (is_file($v)) {
                            $newFile = str_replace($installPathDir,base_path(),$v);
                            if (!is_file($newFile)) {
                                continue;
                            }
                            if (!is_writable($newFile)) {
                                throw new AddonsException(lang('%s,File has no permission to write',[$newFile]));
                            }
                            $fileArr[] = $newFile;
                        } else if (is_dir($v)) {
                            if (!is_writable($v)) {
                                throw new AddonsException(lang('%s,File has no permission to write',[$v]));
                            }
                            $dirArr[] = str_replace($installPathDir,base_path(),$v);
                        }
                    }
                } else if ('template'==$value) { // 静态文件 代码复制
                    $listArr = Dir::instance()->getList($installPathDir);
                    $site = site();

                    foreach ($listArr as $k=>$v) {
                        if (in_array($v,['.','..']) || !isset($site[$v.'_theme'])) { // 必须是模块文件夹
                            continue;
                        }

                        // 模板主题路径
                        $themePath = root_path('template') . $v . DIRECTORY_SEPARATOR . $site[$v.'_theme'] . DIRECTORY_SEPARATOR;

                        // 获取插件安装文件模块目录下的所有文件
                        $temp_installPathDir = $installPathDir . $v . DIRECTORY_SEPARATOR;
                        // 判断是否已经存在该文件
                        $temp = Dir::instance()->rglob( $temp_installPathDir . '*', GLOB_BRACE);
                        if (empty($temp)) {
                            continue;
                        }

                        foreach ($temp as $item) {
                            if (is_file($item)) {
                                $newFile = str_replace($temp_installPathDir, $themePath, $item);
                                if (!is_file($newFile)) {
                                    continue;
                                }
                                if (!is_writable($newFile)) {
                                    throw new AddonsException(lang('%s,File has no permission to write',[$newFile]));
                                }
                                $fileArr[] = $newFile;
                            } else if (is_dir($item)) {
                                $newFile = str_replace($temp_installPathDir, $themePath, $item);
                                if (!is_dir($newFile)) {
                                    continue;
                                }
                                if (!is_writable($item)) {
                                    throw new AddonsException(lang('%s,File has no permission to write',[$item]));
                                }
                                $dirArr[] = $newFile;
                            }
                        }

                        // end 判断是否已经存在该文件 结束
                    }
                } else if ('static'==$value) { // 静态文件 代码复制
                    $addonsStatic = public_path('static'.DIRECTORY_SEPARATOR.'addons');
                    if (is_dir($addonsStatic)) {
                        if (!is_writable($addonsStatic)) {
                            throw new AddonsException(lang('%s,Not writable', [$addonsStatic]));
                        }
                        $static[] = $addonsStatic.$name.DIRECTORY_SEPARATOR;
                    }
                }
            }
        }

        // 文件删除
        if (!empty($fileArr)) {
            foreach ($fileArr as $key=>$value) {
                @unlink($value);
            }
        }
        // 文件夹删除
        if (!empty($dirArr)) {
            $dirArr = array_reverse($dirArr); // 倒序
            foreach ($dirArr as $key=>$value) {
                @rmdir($value); // 只删除空的目录
            }
        }
        // 插件静态资源删除
        if (!empty($static)) {
            $this->clearInstallDir($static,[]);
        }

        // 执行插件禁用方法
        $obj = get_addons_instance($name);
        if (!empty($obj) && method_exists($obj,'disable')) {
            $obj->disable();
        }
    }


    /**
     * 安装、更新前的检查
     * @param string $type 应用类型
     * @param string $name 应用标识
     * @param string $module 应用模块
     * @param bool $update 场景：更新、安装
     * @throws AddonsException
     */
    public function competence($type, $name, $module, $update=false)
    {
        if ('template'==$type) {
            // 模板的情况
            list($templatePath, $staticPath) = $this->getTemplatePath($module);

            if (!is_dir($templatePath)) { // 模板安装目录不存在
                throw new AddonsException(lang('The template depends on the "%s" application and failed!',[$templatePath]));
            }
            if (!is_dir($staticPath)) { // 静态资源安装目录不存在
                throw new AddonsException(lang('The static resource directory "%s" does not exist!',[$staticPath]));
            }
            if (is_dir($templatePath.$name)  && $update===false) { // 不是更新的时候，已经有对应目录抛出异常
                throw new AddonsException(lang('The template installation directory "%s" already exists!',[$templatePath.$name]));
            }
            if (is_dir($staticPath.$name) && $update===false) {
                throw new AddonsException(lang('The static file installation directory "%s" already exists!',[$staticPath.$name]));
            }
        } else {
            $addonsPath = app()->addons->getAddonsPath();
            if ($update===false) {
                $dirArr = $this->getAddonsDir($addonsPath); // 获取插件目录下的所有插件目录名称
                if (in_array($name, $dirArr)  && $update===false) { // 检查插件目录，如果已存在抛出异常
                    throw new AddonsException(lang('%s,existed',[$name]));
                }
            }
        }
    }

    /**
     * 获取模板路径与模板静态路径
     * @param string $module
     * @return string[] 返回模板路径与静态路径
     */
    public function getTemplatePath($module = 'index')
    {
        $addonsPath = config('cms.tpl_path').$module.DIRECTORY_SEPARATOR;
        $staticPath = config('cms.tpl_static').$module.DIRECTORY_SEPARATOR;
        return [$addonsPath, $staticPath];
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
     * 获取下载的应用临时位置[runtime文件夹]
     * @return string
     */
    public function getCloudTmp()
    {
        $dir = runtime_path().'cloud'.DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 清理安装目录或文件
     * @param array $dirArr 清理的路径
     * @param array $fileArr 清理的文件
     */
    private function clearInstallDir($dirArr, $fileArr = [])
    {
        foreach ($dirArr as $value) {
            if (empty($value)) {
                continue;
            }
            Dir::instance()->delDir($value);
        }
        foreach ($fileArr as $value) {
            if (empty($value)) {
                continue;
            }
            @unlink($value);
        }
    }

    /**
     * 下载插件
     * @param string $name 应用标识
     * @param string $version 下载的版本号
     * @return string 返回zip保存路径
     * @throws AddonsException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function download($name, $version)
    {
        try {
            $client = $this->getClient();
            $response = $client->request('get', 'appcenter/download', ['query' => ['name'=>$name, 'version'=>$version, 'cms_version'=>config('ver.cms_version')]]);
            $content = $response->getBody()->getContents();
        }  catch (ClientException $exception) {
            throw new AddonsException($exception->getMessage());
        }

        if (substr($content, 0, 1) === '{') {
            // json 错误信息
            $json = json_decode($content, true);
            throw new AddonsException($json['msg']??lang('Server returns abnormal data'));
        }

        // 保存路径
        $zip = $this->getCloudTmp().$name.'.zip';
        if (file_exists($zip)) {
            @unlink($zip);
        }

        if ($w = fopen($zip, 'w')) {
            fwrite($w, $content);
            fclose($w);
            return $zip;
        }
        throw new AddonsException(lang('No permission to save').'【'.$zip.'】');
    }

    /**
     * 解压缩
     * @param $name
     * @return string
     * @throws \PhpZip\Exception\ZipException
     */
    public function unzip($name)
    {
        $cloudPath = $this->getCloudTmp();
        // 创建解压路径
        $unzipPath = $cloudPath . $name . DIRECTORY_SEPARATOR;
        $zip = $cloudPath . $name .'.zip';

        try {
            @mkdir($unzipPath);
            $zipFile = new \PhpZip\ZipFile();
            $zipFile->openFile($zip);
            $zipFile->extractTo($unzipPath);
        } catch (\PhpZip\Exception\ZipException $e) {
            $zipFile->close();
            $this->clearInstallDir([$unzipPath]);
            throw new AddonsException($e->getMessage());
        } catch (\Exception $e) {
            $zipFile->close();
            $this->clearInstallDir([$unzipPath]);
            throw new AddonsException($e->getMessage());
        }
        return $unzipPath;
    }

    /**
     * 验证info
     * @param $type
     * @param $path
     * @return array info.ini信息
     * @throws AddonsException
     */
    public function checkIni($type, $path)
    {
        // 检查info.ini文件
        $info_file = $path . 'info.ini';
        if (!is_file($info_file)) {
            throw new AddonsException(lang('The info.ini file does not exist'));
        }
        $_info = parse_ini_file($info_file, true, INI_SCANNER_TYPED) ?: [];
        if (empty($_info)) {
            throw new AddonsException(lang('The info.ini format is incorrect'));
        }

        if ('template'==$type) {
            $arr = ['type','module','name','title','author','version'];
        } else {
            $arr = ['type','name','title','author','version','status'];
        }

        foreach ($arr as $key=>$value) {
            if (!array_key_exists($value, $_info)) {
                throw new AddonsException(lang('The info.ini format is incorrect'));
            }
        }
        
        return $_info;
    }

    /**
     * 导入数据库
     * @param string $name 应用标识
     */
    public function importSql($name)
    {
        $sql = app()->addons->getAddonsPath().$name.DIRECTORY_SEPARATOR.'install.sql';
        if (!file_exists($sql)) {
            return false;
        }

        // 导入数据库
        create_sql($sql);
        return true;
    }

    /**
     * 数据库备份
     * @param $filename
     * @return bool
     */
    public function exportSql($filename)
    {
        $db = app('db');
        $list = $db->query('SHOW TABLE STATUS');

        $fp = @fopen($filename, 'w');
        foreach ($list as $key=>$value) {
            $result = $db->query("SHOW CREATE TABLE `{$value['Name']}`");
            $sql = "\n\nDROP TABLE IF EXISTS `{$value['Name']}`;\n";
            $sql .= trim($result[0]['Create Table']) . ";\n\n";
            if (false === @fwrite($fp, $sql)) {
                return false;
            }
            //备份数据记录
            $result = $db->query("SELECT * FROM `{$value['Name']}`");
            foreach ($result as $row) {

                foreach($row as &$v){
                    //将数据中的单引号转义，否则还原时会出错
                    $v = addslashes($v);
                }

                $sql = "INSERT INTO `{$value['Name']}` VALUES ('" . implode("', '", $row) . "');\n";
                if (false === @fwrite($fp, $sql)) {
                    return false;
                }
            }
        }
        @fclose($fp);
        return true;
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