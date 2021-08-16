<?php

namespace iry\http;

class Helper
{
    const VERSION='1.0';

    static function get($url,$args=[],$cfg=[]){
        $gap = strpos($url,'?')>0? '?':'&';
        $queryString = empty($args)?'':$gap.http_build_query($args);

        $request = new Request($url.$queryString,$cfg);
        $res = $request->getResult();
        return current($res);
    }

    static function post($url,$postData=[],$cfg=[]){
        $cfg['post'] = $postData;
        $request = new Request($url,$cfg);
        $res = $request->getResult();
        return current($res);
    }

    static function put($url,$text,$cfg=[]){
        return self::post($url,$text,$cfg);
    }

    static function request($url,$args,$isPost=false,$cfg=[]){
        if($isPost){
            return self::post($url,$args,$isPost,$cfg);
        }else{
            return self::get($url,$args,$isPost,$cfg);
        }
    }

    /**
     * 上传文件
     * uploadFiles
     * @param $url
     * @param string|array $fileSrc
     * @param array $appendParam 附件Post参数
     * @param array $cfg CURL配置
     * @return array
     */

    static function upload($url,$fileSrc,$appendParam=[],$cfg=[]){
        if(is_string($fileSrc)){
            $fileSrc = ['file'=>$fileSrc];
        }
        $fileSrc = array_map(function ($item){return '@'.$item;},$fileSrc);
        return self::post($url,['post'=>array($fileSrc,$appendParam)],$cfg);
    }

    /**
     * 下载文件
     * @param $url
     * @param $dist
     * @param array $cfg
     * @return false|mixed
     */

    static function download($url,$dist,$cfg=[]){
        $request = new Request($url,['to_file'=>$dist]);
        $res = $request->getResult();
        return current($res);
    }
}