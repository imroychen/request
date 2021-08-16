 [中文简体](README.md) --------  **English**

Class http\Request
Through the encapsulation of the "CURL" library,
Easily implement network requests, API requests, etc. in various situations. 
Supports single-threaded and multi-threaded concurrent requests.

## 1. Helper function
```php
\iry\http\Helper::get(url);             //Send a Get request
\iry\http\Helper::post(url,$post);      //Send a Post request
\iry\http\Helper::put(url,'This is test'); 
\iry\http\Helper::request(url,$argvs,isPost);
\iry\http\Helper::upload(url,'/test.jpg',['id'=>100]);              //upload file=>'test.jpg'
\iry\http\Helper::upload(url,['img'=>'/test2.jpg'],['id'=>101]);    //upload
\iry\http\Helper::download(url,$dist);  //Download a file

```
---
## 2. Request Instructions:
7. E.g.
```php
//1. Simple usage
$res = (new Request($url)) -> getResult();
//2. Multi-task concurrent request
//Conventional style
$http = new Request();
//$http->setThread(20);//设置最大20并发，任务总数超过20会分批处理
$http->add(url,config,requestID);//
$http->add(.....);
$thtp->call(function($requestId,$resVi,$currentRequest,$_this){

});
//Usage of chain method
(new Request()) -> setThread(20)->add('url','...')->....->add(url_n,'....')->call(function(){

});
```
Supported methods:
1. getResult ：_Get all request results, return an array, each element is the result of a task_
2. call: _Send the request and process the result through an anonymous function（**Recommended Use**)_
3. setThread：_Set the number of threads_
4. on: _Listen for events_
5. add:_Add a request task_
6. setTasks:_Batch setup tasks_
---
---

## 3. Examples of various usages of Request：
### ①. Simple to use [applicable to small return data]
```php
use iry\http\Request;

//Send a Get request
$res = (new Request($url)) -> getResult();

//Send a Post request
$res = (new Request($url,['post'=>[...])) -> getResult();
```
### ②. Use "ADD" to add multiple tasks to achieve multi-threaded concurrent requests
send request
```php
$http = new Request();
//$http->add($url_1,$cfg_1,request_1_id);  request_1_id 唯一ID 多个请求是用来跟踪请求结果用的
$http->add($url_2,$cfg_2,request_2_id)->add(....)->add(....); //支持连贯调用
//$result = $http->getResult(); or $http->call(..);
```
#### getResult方法
Return a two-dimensional array, requestID is the key, 
and the logic code for processing the result is as follows
```php
$result = $http->getResult();
foreach($result as $itemResult){
	$html = $http->getContent($itemResult);
 	$headerInfo = $http->getInfo($itemResult);
 	//.....更多的逻辑代码......
 	//todo 您的业务代码...
 }
```
The above is the most common business process, but there is a <i><b>disadvantage</b></i>, 
which must be processed in a unified manner after all requests are completed.
During the task, the task repeatedly occupies the memory space. 
At the same time, as long as one is slow, the result processing of all requests will be delayed.

So the following method is recommended: Use the $http->call() method instead of $http->getResult()
#### call Method
arguments：

1. $callback: function($requestID,$resVi,$request,$this){....}
2. $maxRetryTimes: Maximum number of retries on error, default 0 (no retry)

```php
//Pass an anonymous function to Call, the anonymous function will be called after each request is completed
$http->call(function($requestId,$resVi,$request,$http){
    $content = $http->getContent($resVi);
    $info = $http->getInfo($resVi);
    
    if($info['http_code']===0){
        $http->retry($requestId);//Network error Add the retry queue
    }
    //todo Your business code...
},3);

//The second parameter "3": the maximum number of retries
```
### ③. retry： Method can be easily added to retry

    Refer to the code above

### ④. Add tasks in batches
```php
$http = new Request();
$http->setTasks([ [url1,$config],[url2,$config] ]);
$http->call(function(){
    //....
});
```

### ⑤. Listen for events
```php
$http->on( 'before_request', function($this,$每批请求){...} );
$http->on( 'error', function($info,$curlError,$this){...} )
     ->on( 'item_after_request', function($idx,$resVi,$currentRequest,$this){...} )
	 ->call(function(){
 			//.....Your business code...... *
 })
```
Supported events
```php
//网络错误是触发
error: function($info,$curlError,$this)
before_request:function($每批请求,$this){...}
//每个任务请求前触发
item_before_request:function($idx,$config,$this){...}
//每个任务请求对方响应完后触发
item_after_request:function($idx,$resVi,$currentRequest,$this){...}
```
### ⑥. Download files, especially large files.
     Writing files while downloading will not take up much memory
     Usage as above
```php
//Single task single thread request
 $res = (new Request($url,['to_file'=>'file address|ture'])) -> getResult();
 //Multi-task concurrent request
(new Request()) -> add($url_1,['to_file'=>'file address|ture'],requestID_1)
                -> add($url_2,['to_file'=>'file address|ture'],requestID_2)
                ->call( ... )
/Quickly set up tasks in batches
(new Request()) ->setTasks([[url1,['to_file'=>'file/address/or/bool true'],'....']])
->call(...)
```
### ⑦ __construct,add 第二个 config参数
[参考 curl_setopt函数的第二个参数](https://www.php.net/manual/zh/function.curl-setopt.php)

config 为:
```php
    //E.g.: CURLOPT_HTTPHEADER and CURLOPT_ENCODING    
    ['httpheader'=>'...','encoding'=>'...']
    
    // “httpheader” ：CURLOPT_HTTPHEADER removes the value after CURLOPT_, and is not case sensitive。
```
