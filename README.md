**中文简体** ----- [English](README-EN.md)

# 简介

该包通过对CURL的封装，轻松实现各种情况下的网络请求。 支持支持单线程和多线程并发请求。
可以随时无缝切换单线程和多线程的并发请求。
---
# 安装
```shell
composer require iry/request
composer update
```
---
# 使用示例

## 1. Helper 助手函数
```php
use \iry\http\Helper;
Helper::get(url);             //发送一个Get请求
Helper::post(url,$post);      //发送一个post请求
Helper::put(url,'This is test');      //发送一个post请求
Helper::request(url,$argvs,isPost);
Helper::upload(url,'/test.jpg',['id'=>100]);//上传文件
Helper::upload(url,['img'=>'/test2.jpg'],['id'=>101]);//上传文件
Helper::download(url,$dist);  //下载一个文件
```
---
## 2. Request 使用方法:
 示例
```php
//1.简单用法
$res = (new Request($url)) -> getResult();
//2. 多任务并发请求
//常规写法
$http = new Request();
//$http->setThread(20);//设置最大20并发，任务总数超过20会分批处理
$http->add(url,config,requestID);//
$http->add(.....);
$thtp->call(function($requestId,$resVi,$currentRequest,$_this){

});
//连贯写法
(new Request()) -> setThread(20)->add('url','...')->....->add(url_n,'....')->call(function(){

});
```
支持的方法：
1. getResult ：获取所有请求结果，返回一个数组，每一元素是一个任务的结果
2. call: 发送请求并将结果通过匿名闭包函数处理 推荐使用（特别适合大量的请求批量使用）
3. setThread：设置线程数量
4. on: 监听事件
5. add:添加一个请求任务
6. setTasks:批量设置一批任务

---
---

## 3. Request 各种用法的示例:
### ①. 简单使用【适用小的返回数据】
```php
use iry\http\Request;

//发送一个Get请求
$res = (new Request($url)) -> getResult();

//发送一个Post请求
$res = (new Request($url,['post'=>[...])) -> getResult();
```
### ②. 使用 ADD 添加多个任务，实现多线程并发请求
发送请求
```php
$http = new Request();
//$http->add($url_1,$cfg_1,request_1_id);  request_1_id 唯一ID 多个请求是用来跟踪请求结果用的
$http->add($url_2,$cfg_2,request_2_id)->add(....)->add(....); //支持连贯调用
//$result = $http->getResult(); or $http->call(..);
```
#### getResult方法
返回一个二维数组，requestID为键，处理结果的逻辑代码如下
```php
$result = $http->getResult();
foreach($result as $itemResult){
	$html = $http->getContent($itemResult);//获取到结果
 	$headerInfo = $http->getInfo($itemResult);//获取到头部信息
 	//.....更多的逻辑代码......
 	//todo 您的业务代码...
 }
```
以上是常规的业务流程，但是有一个<i><b>弊端</b></i>，必须等所有请求都完成之后在统一处理。
任务过程中任务太多占用内存较多。同时只要一个慢会导致所有请求的结果处理推后。

所以推荐如下方法处理 用 $http->call()方法代替 $http->getResult()
#### call方法
参数：

1. callback function($requestID,$resVi,$request,$this){....}
2. $maxTetryTimes 错误时最大重试次数 默认0（不重试）

```php
//传一个匿名函数给Call 每一个请求完成之后会调用该匿名函数
$http->call(function($requestId,$resVi,$request,$http){
    $content = $http->getContent($resVi);
    $info = $http->getInfo($resVi);
    
    if($info['http_code']===0){
        $http->retry($requestId);//网络错误 加入重试队列
    }
    //todo 你的业务代码...
},3);

//第二个参数 3 最大重试次数
```
### ③. retry方法可以很方便加入重试 

    参考上面代码

### ④. 使用批量添加任务
```php
$http = new Request();
$http->setTasks([ [url1,$config],[url2,$config] ]);
$http->call(function(){
    //....
});
```

### ⑤. 监听动作【事件功能】
```php
$http->on( 'before_request', function($this,$每批请求){...} );
$http->on( 'error', function($info,$curlError,$this){...} )
     ->on( 'item_after_request', function($idx,$resVi,$currentRequest,$this){...} )
	 ->call(function(){
 			//.....更多的逻辑代码...... *
 })
```
支持的事件
```php
//网络错误是触发
error: function($info,$curlError,$this)
before_request:function($每批请求,$this){...}
//每个任务请求前触发
item_before_request:function($idx,$config,$this){...}
//每个任务请求对方响应完后触发
item_after_request:function($idx,$resVi,$currentRequest,$this){...}
```
### ⑥. 下在文件 特别是下载大文件。
    一边下载一边写入文件不会占用太多内存
    用法如上
```php
//单线程获取
 $res = (new Request($url,['to_file'=>'file address|ture'])) -> getResult();
 //多线程获取
(new Request()) -> add($url_1,['to_file'=>'file address|ture'],requestID_1)
                -> add($url_2,['to_file'=>'file address|ture'],requestID_2)
                ->call( ... )
//多线程批量设置任务
(new Request()) ->setTasks([[url1,['to_file'=>'file/address/or/bool true'],'....']])
->call(...)
```
### ⑦ __construct,add 第二个 config参数
常用参数：

1. post: form-data array / raw-value 如:['name'=>'jack','id'=>123456]
   <br>相当于同时 设置 CURLOPT_POST：1 ， CURLOPT_POSTFIELDS：form-data
2. to_file: fielName  将结果写入文件，边下载边写入到文件，
   <br>作用比较适合下载大文件或者大量数据内容。
   [参考 curl_setopt函数的第二个参数](https://www.php.net/manual/zh/function.curl-setopt.php)

config 为:
```php
    //如需要设置 CURLOPT_HTTPHEADER 和 CURLOPT_ENCODING    
    ['httpheader'=>'...','encoding'=>'...']
    //键 “httpheader” 为：CURLOPT_HTTPHEADER 去除 CURLOPT_之后的值，且不区分大小写。
```
