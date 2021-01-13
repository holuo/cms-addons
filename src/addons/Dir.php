<?php
// +----------------------------------------------------------------------
// | ThinkPHP DirectoryIterator实现类 PHP5以上内置了DirectoryIterator类
// +----------------------------------------------------------------------
// | Copyright (c) 2008 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace think\addons;

class Dir
{
    private $_values = array();

    public $error = "";

    protected static $instance;

    /**
     * 单例模式
     * @param string $path
     * @return static
     */
    public static function instance($path = '')
    {
        if (is_null(self::$instance)) {
            self::$instance = new static($path);
        }

        return self::$instance;
    }

    /**
     * 架构函数
     * @param string $path 目录路径
     * @param string $pattern 目录路径
     */
    public function __construct($path='', $pattern='*')
    {
        if ($path && substr($path, -1) != "/")
            $path .= "/";
        $this->listFile($path, $pattern);
    }

    /**
     * 取得目录下面的文件信息
     * @param $pathname
     * @param string $pattern 路径
     */
    public function listFile($pathname, $pattern = '*')
    {
        static $_listDirs = array();
        $guid = md5($pathname . $pattern);
        if (!isset($_listDirs[$guid])) {
            $dir = array();
            $list = glob($pathname . $pattern);
            foreach ($list as $i => $file) {
                //编码转换.把中文的调整一下.
                $dir[$i]['filename'] = preg_replace('/^.+[\\\\\\/]/', '', $file);
                $dir[$i]['pathname'] = realpath($file);
                $dir[$i]['owner'] = fileowner($file);
                $dir[$i]['perms'] = fileperms($file);
                $dir[$i]['inode'] = fileinode($file);
                $dir[$i]['group'] = filegroup($file);
                $dir[$i]['path'] = dirname($file);
                $dir[$i]['atime'] = fileatime($file);
                $dir[$i]['ctime'] = filectime($file);
                $dir[$i]['size'] = filesize($file);
                $dir[$i]['type'] = filetype($file);
                $dir[$i]['ext'] = is_file($file) ? strtolower(substr(strrchr(basename($file), '.'), 1)) : '';
                $dir[$i]['mtime'] = filemtime($file);
                $dir[$i]['isDir'] = is_dir($file);
                $dir[$i]['isFile'] = is_file($file);
                $dir[$i]['isLink'] = is_link($file);
                $dir[$i]['isReadable'] = is_readable($file);
                $dir[$i]['isWritable'] = is_writable($file);
                $dir[$i]['isExecutable']= function_exists('is_executable')?is_executable($file):'';
            }

            // 对结果排序 保证目录在前面
            usort($dir, function ($a, $b) {
                $k = "isDir";
                if ($a[$k] == $b[$k]) return 0;
                return $a[$k] > $b[$k] ? -1 : 1;
            });
            $this->_values = $dir;
            $_listDirs[$guid] = $dir;
        } else {
            $this->_values = $_listDirs[$guid];
        }
        clearstatcache();
    }

    /**
     * 返回数组中的当前元素（单元）
     * @param $arr
     * @return bool|mixed
     */
    public function current($arr)
    {
        if (!is_array($arr)) {
            return false;
        }
        return current($arr);
    }

    /**
     * 文件上次访问时间
     * @return mixed
     */
    public function getATime()
    {
        $current = $this->current($this->_values);
        return $current['atime'];
    }

    /**
     * 取得文件的 inode 修改时间
     * @return mixed
     */
    public function getCTime()
    {
        $current = $this->current($this->_values);
        return $current['ctime'];
    }

    /**
     * 遍历子目录文件信息
     * @return bool|Dir
     */
    public function getChildren()
    {
        $current = $this->current($this->_values);
        if ($current['isDir']) {
            return new Dir($current['pathname']);
        }
        return false;
    }

    /**
     * 取得文件名
     * @return mixed
     */
    public function getFilename()
    {
        $current = $this->current($this->_values);
        return $current['filename'];
    }

    /**
     * 取得文件的组
     * @return mixed
     */
    public function getGroup()
    {
        $current = $this->current($this->_values);
        return $current['group'];
    }

    /**
     * 取得文件的 inode
     * @return mixed
     */
    public function getInode()
    {
        $current = $this->current($this->_values);
        return $current['inode'];
    }

    /**
     * 取得文件的上次修改时间
     * @return mixed
     */
    function getMTime()
    {
        $current = $this->current($this->_values);
        return $current['mtime'];
    }

    /**
     * 取得文件的所有者
     * @return mixed
     */
    public function getOwner()
    {
        $current = $this->current($this->_values);
        return $current['owner'];
    }

    /**
     * 取得文件路径，不包括文件名
     * @return mixed
     */
    public function getPath()
    {
        $current = $this->current($this->_values);
        return $current['path'];
    }

    /**
     * 取得文件的完整路径，包括文件名
     * @return mixed
     */
    public function getPathname()
    {
        $current = $this->current($this->_values);
        return $current['pathname'];
    }

    /**
     * 取得文件的权限
     * @return mixed
     */
    public function getPerms()
    {
        $current = $this->current($this->_values);
        return $current['perms'];
    }

    /**
     * 取得文件的大小
     * @return mixed
     */
    public function getSize()
    {
        $current = $this->current($this->_values);
        return $current['size'];
    }

    /**
     * 取得文件类型
     * @return mixed
     */
    public function getType()
    {
        $current = $this->current($this->_values);
        return $current['type'];
    }

    /**
     * 是否为目录
     * @return mixed
     */
    public function isDir()
    {
        $current = $this->current($this->_values);
        return $current['isDir'];
    }

    /**
     * 是否为文件
     * @return mixed
     */
    public function isFile()
    {
        $current = $this->current($this->_values);
        return $current['isFile'];
    }

    /**
     * 文件是否为一个符号连接
     * @return mixed
     */
    public function isLink()
    {
        $current = $this->current($this->_values);
        return $current['isLink'];
    }

    /**
     * 文件是否可以执行
     * @return mixed
     */
    public function isExecutable()
    {
        $current = $this->current($this->_values);
        return $current['isExecutable'];
    }

    /**
     * 文件是否可读
     * @return mixed
     */
    public function isReadable()
    {
        $current = $this->current($this->_values);
        return $current['isReadable'];
    }

    /**
     * 返回目录的数组信息
     * @return array
     */
    public function toArray()
    {
        return $this->_values;
    }

    /**
     * 判断目录是否为空
     * @param $directory
     * @return bool
     */
    public function isEmpty($directory)
    {
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != "..") {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    /**
     * 取得目录中的结构信息
     * @param $directory
     * @return array|false
     */
    public function getList($directory)
    {
        return scandir($directory);
    }

    /**
     * 遍历文件目录，返回目录下所有文件列表
     * @param $pattern string 路径及表达式
     * @param int $flags 附加选项
     * @param array $ignore 需要忽略的文件
     * @return array|false
     */
    public function rglob($pattern, $flags = 0, $ignore = [])
    {
        //获取子文件
        $files = glob($pattern, $flags);
        //修正部分环境返回 FALSE 的问题
        if (is_array($files) === FALSE)
            $files = array();
        //获取子目录
        $subdir = glob(dirname($pattern) .DIRECTORY_SEPARATOR. '*', GLOB_ONLYDIR | GLOB_NOSORT);
        if (is_array($subdir)) {
            foreach ($subdir as $dir) {
                if ($ignore && in_array($dir, $ignore))
                    continue;
                $files = array_merge($files, $this->rglob($dir . DIRECTORY_SEPARATOR . basename($pattern), $flags, $ignore));
            }
        }
        return $files;
    }

    /**
     * 删除目录（包括下级的文件）
     * @param $directory
     * @param bool $subdir
     * @return bool
     */
    public function delDir($directory, $subdir=true)
    {
        if (is_dir($directory) == false) {
            $this->error = "该目录是不存在！";
            return false;
        }
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != "..") {
                is_dir("$directory/$file") ? $this->delDir($directory.DIRECTORY_SEPARATOR.$file) : unlink($directory.DIRECTORY_SEPARATOR.$file);
            }
        }
        if (readdir($handle) == false) {
            closedir($handle);
            rmdir($directory);
        }
    }

    /**
     * 删除目录下面的所有文件，但不删除目录
     * @param $directory
     * @return bool
     */
    public function delFile($directory)
    {
        if (is_dir($directory) == false) {
            $this->error = "该目录是不存在！";
            return false;
        }
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != ".." && is_file($directory.DIRECTORY_SEPARATOR.$file)) {
                unlink($directory.DIRECTORY_SEPARATOR.$file);
            }
        }
        closedir($handle);
        return true;
    }

    /**
     * 复制目录
     * @param $source
     * @param $destination
     * @return bool
     */
    public function copyDir($source, $destination)
    {
        if (is_dir($source) == false) {
            $this->error = "源目录不存在！";
            return false;
        }
        if (is_dir($destination) == false) {
            mkdir($destination, 0700);
        }
        $handle = opendir($source);
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                is_dir($source . DIRECTORY_SEPARATOR .$file) ?
                                $this->copyDir($source . DIRECTORY_SEPARATOR .$file, $destination.DIRECTORY_SEPARATOR.$file) :
                                copy($source.DIRECTORY_SEPARATOR.$file, $destination.DIRECTORY_SEPARATOR.$file);
            }
        }
        closedir($handle);
    }

    /**
     * 移动文件目录
     * @param $tmpdir
     * @param $newdir
     * @param null $pack
     * @return bool
     */
    public function movedFile($tmpdir, $newdir, $pack = null)
    {
        $list = $this->rglob($tmpdir . '*', GLOB_BRACE);
        if (empty($list)) {
            $this->error = "移动文件到指定目录错误，原因：文件列表为空！";
            return false;
        }

        // 批量迁移文件
        foreach ($list as $file) {
            $newd = str_replace($tmpdir, $newdir, $file);
            // 目录名称
            $dirname = dirname($newd);
            if (file_exists($dirname) == false && mkdir($dirname, 0777, TRUE) == false) {
                $this->error = "创建文件夹{$dirname}失败！";
                return false;
            }

            // 检查缓存包中的文件如果文件或者文件夹存在，但是不可写提示错误
            if (file_exists($file) && is_writable($file) == false) {
                $this->error = "文件或者目录{$file}，不可写！";
                return false;
            }

            // 检查目标文件是否存在，如果文件或者文件夹存在，但是不可写提示错误
            if (file_exists($newd) && is_writable($newd) == false) {
                $this->error = "文件或者目录{$newd}，不可写！";
                return false;
            }

            // 检查缓存包对应的文件是否文件夹，如果是，则创建文件夹
            if (is_dir($file)) {
                // 文件夹不存在则创建
                if (file_exists($newd) == false && mkdir($newd, 0777, TRUE) == false) {
                    $this->error = "创建文件夹{$newd}失败！";
                    return false;
                }
            } else {
                if (file_exists($newd)) {
                    // 删除旧文件（winodws 环境需要）
                    if (!@unlink($newd)) {
                        $this->error = "无法删除{$newd}文件！";
                        return false;
                    }
                }
                // 生成新文件，也就是把下载的，生成到新的路径中去
                if (!@rename($file, $newd)) {
                    $this->error = "无法生成{$newd}文件！";
                    return false;
                }
            }
        }

        //删除临时目录
        $this->delDir($tmpdir);
        //删除文件包
        if (!empty($pack)) {
            @unlink($pack);
        }
        return true;
    }
}