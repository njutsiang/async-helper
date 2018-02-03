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
        $async_helper = new AsyncHelper([
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
            'body' => '请点击邮件中的链接完成验证....'
        ];
        // 第四步、通过异步助手发送邮件
        $async_helper->run('\\SendMailHelper', 'request', [$mail]);
        
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
require_once('SendMailHelper.php');

use l669\AsyncHelper;
use l669\CacheHelper;

$cache_helper = new CacheHelper('127.0.0.1', 11211);
while(true){
    try{
        $async_helper = new AsyncHelper([
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
        sleep(2);
    }
}
```
```
# 在命令行下启动消费者进程，推荐使用 supervisor 来管理进程
php consume.php
```

**支持事务**：需要一次提交执行多个异步方法，事务可以确保完成性。
```php
// 接着上面的示例来说，这里省略了一些重复的代码，下同
$async_helper->beginTransaction();
try{
    $async_helper->run('\\SendMailHelper', 'request', [$mail1]);
    $async_helper->run('\\SendMailHelper', 'request', [$mail2]);
    $async_helper->run('\\SendMailHelper', 'request', [$mail3]);
    $async_helper->commit();
}catch(\Exception $e){
    $async_helper->rollback();
}
```

**阻塞式重试**：当异步进程执行一个方法，方法内部抛出异常时进行重试，一些必须遵循执行顺序的业务就要采用阻塞式的重试，通过指定重试最大阻塞时长来控制。
```php
use l669\CacheHelper;
use l669\AsyncHelper;
$async_helper = new AsyncHelper([
    'host' => '127.0.0.1',
    'port' => '5672',
    'user' => 'root',
    'pass' => '123456',
    'vhost' => '/',
    'cacheHelper' => new CacheHelper('127.0.0.1', 11211),
    'retryMode' => AsyncHelper::RETRY_MODE_REJECT,  // 阻塞式重试
    'maxDuration' => 600                            // 最长重试 10 分钟
]);
$send_mail_helper = new \SendMailHelper();
$mail = new \stdClass();
$mail->from = 'service@yourdomain.com';
$mail->to = 'username@163.com';
$mail->subject = '恭喜你注册成功';
$mail->body = '请点击邮件中的链接完成验证....';
$async_helper->run($send_mail_helper, 'request', [$mail]);

// 如果方法中需要抛出异常来结束程序，又不希望被异步进程重试，可以抛出以下几种错误码，进程捕获到这些异常后会放弃重试：
// l669\AsyncException::PARAMS_ERROR
// l669\AsyncException::METHOD_DOES_NOT_EXIST
// l669\AsyncException::KNOWN_ERROR
```

**非阻塞式重试**：当异步执行的方法内部抛出异常，async-helper 会将该方法重新放进队列的尾部，先执行新进入队列的方法，回头再重试刚才执行失败的方法，通过指定最大重试次数来控制。
```php
use l669\CacheHelper;
use l669\AsyncHelper;
$async_helper = new AsyncHelper([
    'host' => '127.0.0.1',
    'port' => '5672',
    'user' => 'root',
    'pass' => '123456',
    'vhost' => 'new',
    'cacheHelper' => new CacheHelper('127.0.0.1', 11211),
    'queueName' => 'emails.vip',                    // 给付费的大爷走 VIP 队列
    'retryMode' => AsyncHelper::RETRY_MODE_TTL,     // 非阻塞式重试
    'maxRetries' => 10                              // 最多重试 10 次
]);
$mail = new \stdClass();
$mail->from = 'service@yourdomain.com';
$mail->to = 'username@163.com';
$mail->subject = '恭喜你注册成功';
$mail->body = '请点击邮件中的链接完成验证....';
$async_helper->run('\\SendMailHelper', 'request', [$mail]);
```

## 应用和解惑
* 我们采用的是开源的 RabbitMQ 来为我们提供的 AMQP 服务。
* 你的项目部署在拥有很多服务器节点的集群上，每个节点的程序都需要写日志文件，现在的问题就是要收集所有节点上面的日志到一个地方，方便我们及时发现问题或是做一些统计。所有节点都可以使用 async-helper 异步调用一个写日志的方法，而执行这个写日志的方法的进程只需要在一台机器上启动就可以了，这样所有节点的日志就都实时掌握在手里了。
* 做过微信公众号开发的都知道，腾讯微信可以将用户的消息推送到我们的服务器，如果我们在 5s 内未及时响应，腾讯微信会重试 3 次，其实这就是消息队列的应用，使用 async-helper 可以轻松的做和这一样的事情。
* 得益于 RabbitMQ，你可以轻松的横向扩展你的消费者进程的能力，因为 RabbitMQ 天生就支持集群部署，你可以轻松的启动多个消费者进程，或是将消费者进程分布到多台机器上。
* 如果 RabbitMQ 服务不可用怎么办呢？部署 RabbitMQ 高可用服务是容易的，对外提供单一 IP，这个 IP 是个负载均衡，背后是 RabbitMQ 集群，负载均衡承担对后端集群节点的健康检查。
* async-helper 能否承受高并发请求？async-helper 生产者使用的是短连接，也就说在你的 HTTP 还没有响应浏览器的时候 async-helper 就已经结束了工作，你连接 RabbitMQ 的时间是百分之百小于 HTTP 请求的时间的，换言之，只要 RabbitMQ 承受并发的能力超过你的 HTTP 服务的承受并发的能力，RabbitMQ 就永远不会崩，通过横向扩展 RabbitMQ 很容易做到的。

## 和传统 PHP 相比
* 对任何 PHP 方法通过反射进行异步执行；
* 高可用，执行方法进入消息队列，可持久化，即使服务器宕机，执行任务也不丢失；
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
- [安装 supervisor](https://segmentfault.com/n/1330000012996856?token=08f6ca7a324368d6b17efef67ccdaa64)

