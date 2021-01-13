<?php
// +----------------------------------------------------------------------
// | YizCms
// +----------------------------------------------------------------------
// | Copyright (c) 2020-2021 http://www.yizcms.com, All rights reserved.
// +----------------------------------------------------------------------
// | Author: YizCms team <admin@yizcms.com>
// +----------------------------------------------------------------------

declare (strict_types=1);

namespace think\addons;


use think\Exception;
use Throwable;

class AddonsException extends Exception
{

    private $statusCode;

    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        $this->statusCode = $code;
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }
}