# async-helper

## 简介
PHP 的异步进程助手，借助于 AMQP 实现异步执行 PHP 的方法，将一些很耗时、追求高可用、需要重试机制的操作放到异步进程中去执行，将你的 HTTP 服务从繁重的业务逻辑中解脱出来。以一个较低的成本将传统 PHP 业务逻辑转换成非阻塞、高可用、可扩展的异步模式。

## 依赖
- php 7.1.5+
- ext-bcmath
- ext-amqp 1.9.1+
- ext-memcached 3.0.3+

## 安装
通过 composer 安装
```
composer require l669/async-helper
```
或直接下载项目源码
```
wget https://github.com/l669306630/php-async-helper/archive/master.zip
```

## 使用范例
**业务逻辑**：这里定义了很多等待被调用的类和方法，在你的项目中这可能是数据模型、或是一个发送邮件的类。
```php
<?php
class SendMailHelper 
{
    /**
     * @param array $mail
     * @throws Exception
     */
    public static function request($mail)
    {
        // 在这里发送邮件，或是通过调用第三方提供的服务发送邮件
        // 发送失败的时候你抛出了异常，希望被进程捕获，并按设定的规则进行重试
    }	
}
```

**生产者**：通常是 HTTP 服务，传统的 PHP 项目或是一个命令行程序，接收到某个请求或指令后进行一系列的操作。
```php
<?php 
use l669\AsyncHelper;
class UserController
{
    public function register()
    {
        // 假设这是一个用户注册的请求，用户提交了姓名、邮箱、验证码
        // 第一步、校验用户信息
        // 第二步、实例化异步助手，这时候会连接 AMQP
        $async_helper = AsyncHelper([
            'host' => '127.0.0.1',
            'port' => '5672',
            'user' => 'root',
            'pass' => '123456',
            'vhost' => '/'
        ]);
        // 第三步、保存用户信息到数据库
        $mail = [
            'from' => 'service@yourdomain.com', 
            'to' => 'username@163.com', 
            'subject' => '恭喜你注册成功',
            'body' => '请点击邮件中的链接完成验证，吧啦啦啦' 
        ];
        // 第四步、通过异步助手发送邮件
        $async_helper->run('SendMailHelper', 'request', [$mail]);
        
        // 这是同步的模式去发送邮件，如果邮件服务响应迟缓或异常，就会直接影响该请求的响应时间，甚至丢失这封重要邮件
        // SendMailHelper::request($mail);
    }
}
```

**消费者**：PHP 的异步进程，监听消息队列，执行你指定的方法。并且该消费者进程是可扩展的高可用的服务，这一切都得益于 AMQP，这是系统解耦、布局微服务的最佳方案。

consume.php
```php
<?php
require_once('vendor/autoload.php');
use l669\AsyncHelper;
use l669\CacheHelper;

$cache_helper = new CacheHelper('127.0.0.1', 11211);
while(true){
    try{
        $async_helper = AsyncHelper([
            'host' => '127.0.0.1',
            'port' => '5672',
            'user' => 'root',
            'pass' => '123456',
            'vhost' => '/',
            'cacheHelper' => $cache_helper
        ]);
        $async_helper->consume();
    }catch(Exception $e){
        // 可以在这里记录一些日志
    }
}
```
```
php consume.php
```

## 和传统 PHP 相比
* 对任何 PHP 方法通过反射进行异步执行；
* 高可用，执行方法进入消息队列，即使服务器宕机，执行任务也不丢失；
* 高可用，对异常可以进行不限次数和时间的重试，重试次数和时间可配置；
* 支持对多个异步方法包含在事务中执行，支持回滚事务；
* 方法的参数类型支持除资源类型（resource）和回调函数（callable）外的任意类型的参数；
* 得益于 AMQP，异步方法可以承受高并发、高负载，支持集群部署、横向扩展；
* 低延时，实测延时时间 0.016 ～ 0.021s；
* 适用于：日常数据库操作、日志收集、金融交易、消息推送、发送邮件和短信、数据导入导出、计算大量数据生成报表；

## 附录
- [安装 memcached](https://segmentfault.com/n/1330000012854656)
- [安装 rabbitmq](https://segmentfault.com/n/1330000012865854?token=98d34e1eac9cac279d84abba0c45f834)
- [安装 php7.1.5、ext-amqp、ext-memcached](https://segmentfault.com/n/1330000012854879?token=4a1e0e0debb594ba20d091e6a7fa40d2)

