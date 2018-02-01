# php-async-helper

## 简介
PHP 的异步进程助手，借助于 AMQP 实现异步执行 PHP 的方法，将一些很耗时、追求高可用、需要重试机制的操作放到异步进程中去执行，将你的 HTTP 服务从繁重的业务逻辑中解脱出来。以一个较低的成本将传统 PHP 业务逻辑转换成非阻塞、高可用、可扩展的异步模式。

## 安装
通过 composer 安装
```
composer require l669/async-helper
```
或直接下载项目源码
```
wget https://github.com/l669306630/php-async-helper/archive/master.zip
```

## 使用
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
		    // 
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
while(true){
    try{
        $async_helper = AsyncHelper([
        
        ]);
        $async_helper->consume();
    }catch(Exception $e){
		
    }
}
```
```
php consume.php
```

## 附录



