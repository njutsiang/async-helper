<?php
namespace l669;

use l669\AsyncException;

class AsyncHelper
{

	const QUEUE_DEFAULT = 'async';

	const RETRY_MODE_TTL = 1;

	const RETRY_MODE_REJECT = 2;

	public $host;

	public $port;

	public $user;

	public $pass;

	public $vhost;

	/**
	 * @var string
	 */
	public $queueName = self::QUEUE_DEFAULT;

	/**
	 * 重试模式：1 ttl、2 reject
	 * @var integer
	 */
	public $retryMode = self::RETRY_MODE_TTL;

	/**
	 * 最大重试次数，首次执行不计入重试次数
	 * 必须和 self::RETRY_MODE_TTL 配合使用
	 * @var integer
	 */
	public $maxRetries = -1;

	/**
	 * 最大持续时长，单位 秒
	 * 必须和 self::RETRY_MODE_REJECT 配合使用
	 * @var integer
	 */
	public $maxDuration = -1;

	/**
	 * @var \AMQPConnection
	 */
	public $connection;

	/**
	 * @var \AMQPChannel
	 */
	public $channel;

	/**
	 * @var \AMQPExchange
	 */
	public $exchange;

	/**
	 * @var \AMQPQueue
	 */
	public $queue;

	/**
	 * 事务是否已经开始
	 * @var bool
	 */
	public $transactionIsBegin = false;

	/**
	 * 事务消息暂存队列
	 * @var array
	 */
	public $transactionMessages = [];

	/**
	 * 参数计数，用于生成缓存的键
	 * @var integer
	 */
	public $varCount = 0;

	/**
	 * @var \l669\CacheHelper
	 */
	public $cacheHelper;

	private static $model = null;

	/**
	 * 单例对象
	 * @param array $properties
	 * @return AsyncHelper|null
	 */
	public static function model($properties = [])
	{
		if(self::$model === null){
			self::$model = new self($properties);
		}else{
			foreach($properties as $key => $value){
				self::$model->$key = $value;
			}
		}
		return self::$model;
	}

	/**
	 * 构造方法
	 * AsyncHelper constructor.
	 * @param array $properties
	 */
	public function __construct($properties = [])
	{
		foreach($properties as $key => $value){
			$this->$key = $value;
		}
	}

	/**
	 * 异步执行方法
	 * @param string|object $class
	 * @param string $method
	 * @param array $args
	 * @param array $options
	 * @return bool
	 */
	public function run($class, $method, $args = [], $options = [])
	{
		try{
			// 缓存对象类型（object）的参数
			$args = self::recursiveArray($args, function($item){
				if(in_array(gettype($item), ['object', 'resource'])){
					$this->varCount++;
					$key = 'acync.cache.'.$this->varCount.'.'.uniqid();
					if(!$this->cacheHelper->set($key, $item, 24 * 3600)){
						throw new AsyncException(AsyncException::CACHE_ERROR);
					}
					return $key;
				}else{
					return $item;
				}
			});
			// 缓存实例
			$instance = self::getArrayValue($options, ['instance']);
			if(gettype($class) == 'object'){
				$instance = $class;
				$class = get_class($class);
			}
			if($instance){
				$this->varCount++;
				$key = 'acync.cache.'.$this->varCount.'.'.uniqid();
				if(!$this->cacheHelper->set($key, $instance, 24 * 3600)){
					throw new AsyncException(AsyncException::CACHE_ERROR);
				}
				$instance = $key;
			}
			// 构建消息体
			$message = [
				'class' => $class,
				'method' => $method,
				'args' => $args,
				'instance' => $instance,
				'instance_args' => self::getArrayValue($options, ['instance_args'], [])
			];
			$message = json_encode($message, JSON_UNESCAPED_UNICODE);
			// 如果开启事务，则将消息暂存到队列
			if($this->transactionIsBegin){
				$this->transactionMessages[] = $message;
				return true;
			}else{
				return $this->basicPublish($message);
			}
		}catch(\Exception $e){
			return false;
		}
	}

	/**
	 * 建立 AMQP 连接
	 * @param integer|null $qos_count
	 * @return \AMQPConnection
	 * @throws \Exception
	 */
	public function createConnection($qos_count = null)
	{
		$this->connection = new \AMQPConnection([
			'host' => $this->host,
			'port' => $this->port,
			'vhost' => $this->vhost,
			'login' => $this->user,
			'password' => $this->pass
		]);
		$this->connection->connect();
		$this->channel = new \AMQPChannel($this->connection);
		if($qos_count){
			$this->channel->qos(0, $qos_count);
		}
		$this->exchange = new \AMQPExchange($this->channel);
		$this->exchange->setName($this->queueName.'.direct');
		$this->exchange->setType(AMQP_EX_TYPE_DIRECT);
		$this->exchange->setFlags(AMQP_DURABLE);
		$this->exchange->declareExchange();
		$this->queue = new \AMQPQueue($this->channel);
		$this->queue->setName($this->queueName);
		$this->queue->setFlags(AMQP_DURABLE);
		$this->queue->declareQueue();
		$this->queue->bind($this->queueName.'.direct');
		return $this->connection;
	}

	/**
	 * 关闭 AMQP 连接
	 */
	public function closeConnection()
	{
		try{
			if($this->connection){
				$this->connection->disconnect();
				$this->connection = null;
				$this->channel = null;
				$this->exchange = null;
				$this->queue = null;
			}
		}catch(\Exception $e){ }
	}

	/**
	 * 开始事务
	 * @throws \Exception
	 */
	public function beginTransaction()
	{
		$this->createConnection();
		$this->channel->startTransaction();
		$this->transactionIsBegin = true;
		$this->transactionMessages = [];
	}

	/**
	 * 提交事务
	 * @throws \Exception
	 */
	public function commit()
	{
		$exception = null;
		try{
			$this->basicPublish($this->transactionMessages);
			$this->channel->commitTransaction();
		}catch(\Exception $e){
			$exception = $e;
		}
		$this->clearTransaction();
		if($exception){
			throw $exception;
		}
	}

	/**
	 * 回滚事务
	 */
	public function rollback()
	{
		try{
			if($this->channel){
				$this->channel->rollbackTransaction();
			}
		}catch(\Exception $e){ }
		$this->clearTransaction();
	}

	/**
	 * 关闭连接，清理事务消息暂存队列
	 */
	public function clearTransaction()
	{
		$this->closeConnection();
		$this->transactionIsBegin = false;
		$this->transactionMessages = [];
	}

	/**
	 * 向 AMQP 发送消息
	 * @param string|string[] $messages
	 * @return bool
	 * @throws \Exception
	 */
	public function basicPublish($messages)
	{
		if(!$this->transactionIsBegin){
			$this->createConnection();
		}
		if(!is_array($messages)){
			$messages = array($messages);
		}
		foreach($messages as $message){
			$attributes = [
				'content_type' => 'application/json',
				'headers' => [
					'x-start' => time(),
					'x-retries' => 0,
					'x-max-retries' => $this->maxRetries,
					'x-max-duration' => $this->maxDuration,
					'x-retry-mode' => $this->retryMode
				]
			];
			$this->exchange->publish($message, null, AMQP_NOPARAM, $attributes);
		}
		if(!$this->transactionIsBegin){
			$this->closeConnection();
		}
		return true;
	}

	/**
	 * 设置队列名称
	 * @param string $queue_name
	 */
	public function setQueueName($queue_name)
	{
		$this->queueName = $queue_name;
	}

	/**
	 * 设置最大重试次数
	 * @param integer $max_retries
	 */
	public function setMaxRetries($max_retries)
	{
		$this->retryMode = self::RETRY_MODE_TTL;
		$this->maxRetries = $max_retries;
	}

	/**
	 * 设置最大重试时间
	 * @param integer $max_duration
	 */
	public function setMaxDuration($max_duration)
	{
		$this->retryMode = self::RETRY_MODE_REJECT;
		$this->maxDuration = $max_duration;
	}

	/**
	 * 遍历数组，对所有元素执行回调函数，并覆盖原元素值
	 * @param array $data
	 * @param callable $callback
	 */
	public static function recursiveArray($data, $callback)
	{
		foreach($data as $k => $item){
			if(is_array($item)){
				$data[$k] = self::recursiveArray($item, $callback);
			}else{
				$data[$k] = $callback($item);
			}
		}
		return $data;
	}

	/**
	 * 对多维数组的取值操作
	 * @param array $data
	 * @param array $keys
	 * @param mixed|null $default
	 * @return mixed|null
	 */
	public static function getArrayValue($data, $keys, $default = null)
	{
		if(!(is_array($data) && is_array($keys))){
			return $default;
		}
		$key = array_shift($keys);
		if(!((is_string($key) || is_numeric($key)) && isset($data[$key]))){
			return $default;
		}
		if(count($keys) === 0){
			return $data[$key];
		}else{
			return self::getArrayValue($data[$key], $keys, $default);
		}
	}

	/**
	 * @throws \Exception
	 */
	public function consume()
	{
		$this->createConnection(1);
		$this->queue->consume(array($this, 'consumeCallback'));
		$this->closeConnection();
	}

	/**
	 * 消费进程的回调函数
	 * @param \AMQPEnvelope $envelope
	 * @param \AMQPQueue $queue
	 * @throws \Exception
	 */
	public function consumeCallback($envelope, $queue)
	{
		$delivery_tag = $envelope->getDeliveryTag();
		$body = $envelope->getBody();
		$cache_keys = array();
		try{
			// 取参数
			$params = json_decode($body, true);
			if(!(is_array($params) && isset($params['class'], $params['method']))){
				throw new AsyncException(AsyncException::PARAMS_ERROR, $params);
			}

			// 类的反射
			$class = new \ReflectionClass($params['class']);
			if(!$class->hasMethod($params['method'])){
				throw new AsyncException(AsyncException::METHOD_DOES_NOT_EXIST, $params);
			}
			$instance_args = self::getArrayValue($params, ['instance_args']);
			if(!$instance_args){
				$instance_args = array();
			}
			$args = self::getArrayValue($params, ['args']);
			if(!$args){
				$args = array();
			}

			// 从缓存中读取对象类型的参数
			$args = self::recursiveArray($args, function($item) use(&$cache_keys){
				if(is_string($item) && preg_match('/^acync\.cache\..*$/', $item)){
					$cache_keys[] = $item;
					return $this->cacheHelper->get($item);
				}else{
					return $item;
				}
			});

			// 获取实例
			if(($instance = self::getArrayValue($params, ['instance']))){
				if(!(is_string($instance) && preg_match('/^acync\.cache\..*$/', $instance))){
					throw new AsyncException(AsyncException::INSTANCE_ERROR);
				}
				$cache_keys[] = $instance;
				$object = $this->cacheHelper->get($instance);
			}else{
				$object = $class->newInstanceArgs($instance_args);
			}
			if(!$object){
				throw new AsyncException(AsyncException::INSTANCE_ERROR);
			}

			// 执行方法
			$method = new \ReflectionMethod($object, $params['method']);
			$method->invokeArgs($object, $args);

		}catch(\Exception $e){
			$this->asyncException($e, $envelope, $queue);
			return;
		}
		foreach($cache_keys as $cache_key){
			$this->cacheHelper->delete($cache_key);
		}
		$queue->ack($delivery_tag);
	}

	/**
	 * 处理异常，根据异常类型和错误码，来决定是否重试消息
	 * @param \Exception $e
	 * @param \AMQPEnvelope $envelope
	 * @param \AMQPQueue $queue
	 * @throws \Exception
	 */
	public function asyncException($e, $envelope, $queue)
	{
		$delivery_tag = $envelope->getDeliveryTag();
		$codes = array(
			AsyncException::PARAMS_ERROR,
			AsyncException::METHOD_DOES_NOT_EXIST,
			AsyncException::KNOWN_ERROR
		);
		if(($e instanceof AsyncException) && in_array($e->getCode(), $codes)){
			$queue->ack($delivery_tag);
		}else{
			$this->retry($envelope, $queue);
		}
	}

	/**
	 * 重试消息
	 * 在 3s 后再重试一次，并在 header 中记录递增的重试次数和开始时间
	 * @param \AMQPEnvelope $envelope
	 * @param \AMQPQueue $queue
	 * @throws \Exception
	 */
	public function retry($envelope, $queue)
	{
		$delivery_tag = $envelope->getDeliveryTag();
		$body = $envelope->getBody();
		$headers = $envelope->getHeaders();
		$retry_mode = self::getArrayValue($headers, ['x-retry-mode'], AsyncHelper::RETRY_MODE_TTL);
		if($retry_mode == AsyncHelper::RETRY_MODE_TTL){
			$queue->ack($delivery_tag);
			$retries = self::getArrayValue($headers, ['x-retries'], 0);
			$max_retries = self::getArrayValue($headers, ['x-max-retries']);
			if($max_retries > 0 && $retries >= $max_retries){
				return;
			}
			if(isset($headers['x-death'])){
				unset($headers['x-death']);
			}
			$headers['x-retries'] = $retries + 1;
			$this->publishTTLMessage($queue->getName(), $body, 3, $headers);
		}else{
			$start = self::getArrayValue($headers, ['x-start']);
			$max_duration = self::getArrayValue($headers, ['x-max-duration']);
			if($start && $max_duration && time() >= ($start + $max_duration)){
				$queue->ack($delivery_tag);
				return;
			}
			sleep(3);
			$queue->reject($delivery_tag, AMQP_REQUEUE);
		}
	}

	/**
	 * 发送一条延时消息到 AMQP
	 * @param string $queue
	 * @param string $message
	 * @param integer $seconds
	 * @throws \Exception
	 */
	public function publishTTLMessage($queue_name, $message, $seconds, $headers = array())
	{
		$result = null;
		$ttl_queue_name = $queue_name.'.ttl_'.$seconds;
		$ttl_exchange_name = $ttl_queue_name.'.direct';
		$exchange_name = $queue_name.'.direct';
		$attributes = array(
			'delivery_mode' => AMQP_DURABLE
		);
		if($headers){
			$attributes['headers'] = $headers;
		}
		if($seconds){
			$ttl_exchange = new \AMQPExchange($this->channel);
			$ttl_exchange->setName($ttl_exchange_name);
			$ttl_exchange->setType(AMQP_EX_TYPE_DIRECT);
			$ttl_exchange->setFlags(AMQP_DURABLE);
			$ttl_exchange->declareExchange();
			$ttl_queue = new \AMQPQueue($this->channel);
			$ttl_queue->setName($ttl_queue_name);
			$ttl_queue->setFlags(AMQP_DURABLE);
			$ttl_queue->setArgument('x-message-ttl', $seconds * 1000);
			$ttl_queue->setArgument('x-dead-letter-exchange', $exchange_name);
			$ttl_queue->declareQueue();
			$ttl_queue->bind($ttl_exchange_name);
			return $ttl_exchange->publish($message, null, AMQP_NOPARAM, $attributes);
		}else{
			return $this->exchange->publish($message, null, AMQP_NOPARAM, $attributes);
		}
	}

}