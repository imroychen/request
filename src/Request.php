<?php

namespace iry\http;

/**
 * @event
 * error: function($info,$curlError,$this)
 * before_request:function($每批请求,$this){...}
 * item_before_request:function($idx,$config,$this){...} //每个任务请求前触发 / Triggered before each task request
 * item_after_request:function($idx,$resVi,$currentRequest,$this){...} //每个任务请求并在对方响应完后触发 / Triggered after each task request
 */

class Request {
    const CMD_SKIP = 'skip';
    private $_result = [];

    private $_tmpPath;

    private $_requestList = [];
    private $_retryList = [];
    private $_defaultCfg;//array
    private $_threadQty=10;
    private $_listeners = []; //事件监听器 listeners

    private $_downloadToFiles = [];//下载文件是使用 . temp var:download fiels

    private $_viList = [];


    /**
     * constructor.
     * @param string $url
     * @param array $defaultCfg 公共配置/configs
     */

    function __construct($url='',$defaultCfg=[])
    {

        $this->_defaultCfg = $defaultCfg;

        if(strpos($url,'http')===0) {
            $k = uniqid();
            $this->_requestList[$this->_encodeId($k)] = ['url' => $url];
        }

        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'iry-http-request-tmp';
        if(!is_dir($tmpDir)){
            mkdir($tmpDir,0777,true);
        }
        $this->_tmpPath= $tmpDir.DIRECTORY_SEPARATOR;

    }

    /**
     * @param callable|null $callback
     * 		param:
     * 			$offset[当前Key/线程ID],$this,$result
     * 				$this->getContent($result);//获取到结果
     * 				$this->getInfo($result);//获取到头部信息
     * @return array
     */

    public function call($callback,$maxRetryTimes = 0){
        $this->_result= [];
        $this->_retryList = [];

        $this->_call($callback);

        //错误重试
        $i = 0;
        while ($i<$maxRetryTimes && count($this->_retryList)>0){
            $this->_requestList = $this->_retryList;
            $this->_retryList = [];
            $this->_call($callback);
            $i++;
        }
        return $this->_result;
    }

    private function _call($callback)
    {
        $len = count($this->_requestList);
        //单线程
        if($len == 1 || $this->_threadQty = 1){

            foreach ($this->_requestList as $idx=>$request){
                $_resItem = $this->_curl($request,$idx);
                $destIdx = $this->_decodeId($idx);
                if (is_callable($callback)) {
                    $this->_result[$destIdx] = call_user_func($callback, $destIdx, $this, $_resItem);
                }else{
                    $this->_result[$destIdx] = $_resItem;
                }
                $this->_rmBufferByVi($_resItem);
            }

        }else {
            //多线程线程并发
            $pageSize = min($this->_threadQty,$len);
            for ($i = 0; $i < $len; $i += $pageSize) {
                $_res = $this->_multiCurl(array_slice($this->_requestList, $i, $pageSize));
                foreach ($_res as $idx => $_resItem) {
                    $destIdx = $this->_decodeId($idx);
                    if (is_callable($callback)) {
                        $this->_result[$destIdx] = call_user_func($callback, $destIdx, $this, $_resItem);
                    }else{
                        $this->_result[$destIdx]=$_resItem;
                    }
                    $this->_rmBufferByVi($_resItem);
                }
            }
        }
    }

    public function getResult(){
        $res =[];
        $this->_call(function ($idx,$request,$_resItem)use($res){
            $res[$idx]=[
                'result' => $this->getContent($_resItem),
                'info' => $this->getInfo($_resItem),
            ];
        });
        return $res;
    }

    /**
     * 重试指定的任务 / Retry task
     * @param $idx
     * @return bool
     */
    public function retry($idx){
        $destIdx = $this->_decodeId($idx);
        if(isset($this->_requestList[$idx])) {
            $this->_retryList[$destIdx] = $this->_requestList[$idx];
            return true;
        }
        return false;
    }

    /**
     * 添加任务 / add task
     * @param $url
     * @param $cfg
     * @param null $k
     * @return $this
     */
    public function add($url,$cfg,$k=null){
        if (is_null($k)){
            $k = uniqid().mt_rand(10,1000);
        }
        $this->_requestList[$this->_encodeId($k)] =array_merge( ['url'=>$url],$cfg);
        return $this;
    }

    /**
     * 批量设置任务
     * Batch setup tasks
     *
     * @param array $params
     * [
     *      ['ulr',config1 array]
     *      'url'
     * ]
     * @return $this
     */


    function setTasks($params){
        $this->_requestList = [];
        foreach ($params as $k=>$task){
            if(is_string($task)){
                $this->_requestList[$this->_encodeId($k)] = ['url'=>$task];
            }else{
                $this->_requestList[$this->_encodeId($k)] = array_merge(['url'=>$task[0],$task[1]]);
            }
        }
        return $this;
    }

    /**
     * 设置并发的线程数量
     * Set the number of concurrent threads
     *
     * @param $qty
     */

    public function setThread($qty){
        $this->_threadQty = $qty;
    }


    /**
     * 事件监听
     * Listen for events
     *
     * @param string $event
     * @param callable $callback
     * @return $this
     */

    public function on($event,$callback){
        $event = trim($event);
        $this->_listeners[$event] = $callback;
        return $this;
    }


    /**
     * 获取对方响应的头信息
     * getInfo
     * @param $vi
     * @return mixed
     */
    public function getInfo($vi){
        return unserialize(file_get_contents($this->getBufferPath($vi,"info")));
    }

    /**
     * 获取对方响应的内容
     * Get the content of the other party's response
     *
     * @param $vi
     * @return false|string
     */

    public function getContent($vi){
        return file_get_contents($this->getBufferPath($vi,"content") );
    }

    public function getBufferPath($vi,$type='content'){
        return $this->_tmpPath.'/'.$vi.".".$type.".buffer";
    }

    private function _saveToBuffer($itemConn,$idx,$isMulti=true){
        if($isMulti){
            $data = curl_multi_getcontent($itemConn);
        }else{
            $data = curl_exec($itemConn);
        }

        $info = curl_getinfo($itemConn);

        $vi = date('ymdH-').uniqid();
        $this->_viList[$vi]=1;

        if(intval($info['http_code'])===0) {
            $this->_fire('error',[$info,curl_error($itemConn)]);
        }
        //下载大文件的时候使用//边下边写入文件
        //Usually used when downloading large files, while downloading and writing files
        if(isset($this->_downloadToFiles[$idx]) && $this->_downloadToFiles[$idx]){
            $data = $this->_downloadToFiles[$idx][0];
            fclose($this->_downloadToFiles[$idx][1]);
            $this->_downloadToFiles[$idx][1] = false;
        }
        file_put_contents($this->getBufferPath($vi, "content"), $data);
        file_put_contents($this->getBufferPath($vi,"info"),serialize($info));

        return $vi;
    }


    private function _defaultCfg(){
        $ua = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.190 Safari/537.36';
        return array_merge([
            'ssl_verifypeer'=>0,
            'ssl_verifyhost'=>0,
            'useragent'=>$ua,
            'returntransfer'=>1,
            'timeout'=>60,
            'connecttimeout'=>15,
        ],$this->_defaultCfg);
    }

    private function _processCfg($cfg,$idx){

        if(isset($cfg['postfields']) && $cfg['postfields'] === 0) {
            $cfg['post'] = 1;
            $cfg['postfields'] = '';
        }elseif ( isset($cfg['post']) && !empty($cfg['post']) && !isset($cfg['postfields']) ) {
            $post = /*is_array($cfg['post']) ? http_build_query($cfg['post']) : */$cfg['post'];
            $cfg['post'] = 1;
            $cfg['postfields'] = $post;
        }

        $cfg = array_merge($this->_defaultCfg(),$cfg);

        //下载文件使用
        if(isset($cfg['to_file']) && $cfg['to_file']){//to_file
            $f = is_bool($cfg['to_file'])?($this->_tmpPath.uniqid().mt_rand(100,999)):$cfg['to_file'];
            $fp = fopen ($f, 'w+');
            unset($cfg['returntransfer']);
            $cfg['file'] = $fp;
            $this->_downloadToFiles[$idx] = [$f,$fp];//记录该任务存储路径，留完成后关闭 和重试的时候使用
        }elseif ( isset($this->_downloadToFiles[$idx]) && $this->_downloadToFiles[$idx][0] ){
            //重试的时候 $cfg['to_file']已经被删除
            $fp = fopen ($this->_downloadToFiles[$idx][0] , 'w+');
            $this->_downloadToFiles[$idx][1] = $fp;
            $cfg['file'] = $fp;
        }

        if(isset($cfg['@transfer']) && !empty($cfg['@transfer'])){//走中转代理包装
            $url = $cfg['@transfer'];
            unset($cfg['@transfer']);

            $cfg = [
                'post'=>1,
                'postfields'=>http_build_query(['cfg'=>json_encode($cfg)]),
                'url'=>$url
            ];
        }

        $this->_fire('item_before_request',[$this->_decodeId($idx),$cfg]);
        $conn = curl_init();
        foreach ($cfg as $k => $v) {
            $k = 'CURLOPT_' . strtoupper($k);
            if (defined($k)) {
                curl_setopt($conn, constant($k), $v);
            }
        }
        return $conn;
    }

    /**
     * //每个线程的 ID处理 强制转为字符串
     * @param $key
     * @return string
     */

    private function _encodeId($key){
        return 'x_'.$key;
    }

    private function _decodeId($key){
        return substr($key,2);
    }

    private function _fire($event,$args){
        if (isset($this->_listeners[$event]) && is_callable($this->_listeners[$event])) {
            $args[] = $this;
            call_user_func_array($this->_listeners[$event], $args);
        }
    }

    private function _rmBufferByVi($vi){
        if(file_exists($this->_tmpPath.'/'.$vi.".content.buffer")){
            unlink($this->_tmpPath.'/'.$vi.".content.buffer");
        }

        if(file_exists($this->_tmpPath.'/'.$vi.".info.buffer")){
            unlink($this->_tmpPath.'/'.$vi.".info.buffer");
        }

        if(isset($this->_viList[$vi])){
            unset($this->_viList[$vi]);
        }
    }

    private function _curl($arr,$i){
        $connection = $this->_processCfg($arr,$i);
        //发送请求
        $r = $this->_saveToBuffer($connection,$i,false);
        //echo curl_error($connection);
        //关闭连接
        curl_close($connection);
        return $r;
    }

    private function _multiCurl($request){

        $mh = curl_multi_init();
        $conn = [];
        $cmd = [];
        foreach ($request as $i => $cfg) {
            if($cfg===self::CMD_SKIP){
                $cmd[$i] = self::CMD_SKIP;
            }else {
                $conn[$i] = $this->_processCfg($cfg,$i);
                curl_multi_add_handle($mh, $conn[$i]);
            }
        }

        $connLen = count($conn);

        //============执行批处理句柄========
        if($connLen>0) {
            $running = null;// 执行批处理句柄
            do {
                usleep(5000);
                curl_multi_exec($mh, $running);
            } while ($running > 0);
        }
        //=====================================

        //缓冲结果 并快速断开连接 防止线程过多累积消耗内存
        $res = [];
        foreach ($request as $i => $v) {
            if(isset($cmd[$i]) && $cmd[$i] === self::CMD_SKIP ){
                $res[$i] = false;
            }else {
                $res[$i] = $this->_saveToBuffer($conn[$i],$i);
                curl_multi_remove_handle($mh, $conn[$i]);
                curl_close($conn[$i]);
            }

            $this->_fire('item_after_request',[$this->_decodeId($i),$res[$i],$v]);
        }

        curl_multi_close($mh);
        unset($this->_mh,$conn);
        return $res;
    }

}