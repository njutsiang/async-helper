<?php
namespace l669;

/**
 * Class AsyncException
 * @package l669
 */
class AsyncException extends \Exception
{

	const DB_CONNECTION_FAILED = 4000;
	const DB_ERROR = 4001;
	const PARAMS_ERROR = 4002;
	const METHOD_DOES_NOT_EXIST = 4003;
	const CACHE_ERROR = 4005;
	const INSTANCE_ERROR = 4006;
	const KNOWN_ERROR = 4007;

	/**
	 * @return array
	 */
	public static function errors()
	{
		return array(
			self::DB_CONNECTION_FAILED => '数据库连接失败',
			self::DB_ERROR => '数据库错误',
			self::PARAMS_ERROR => '参数错误',
			self::METHOD_DOES_NOT_EXIST => '方法不存在',
			self::CACHE_ERROR => '缓存服务不可用',
			self::INSTANCE_ERROR => '实例错误',
			self::KNOWN_ERROR => '已知错误'
		);
	}

	/**
	 * @param integer $code
	 * @return string|null
	 */
	public static function errorMessage($code)
	{
		$errors = self::errors();
		return isset($errors[$code]) ? $errors[$code] : '';
	}

	/**
	 * AsyncException constructor.
	 * @param integer $code
	 * @param string $message
	 */
	public function __construct($code = 0, $message = '')
	{
		if(!$message){
			$message = self::errorMessage($code);
		}
		parent::__construct($message, $code);
	}

}