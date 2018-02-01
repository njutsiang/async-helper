<?php
namespace l669;

/**
 * Class CacheHelper
 * @package l669
 */
class CacheHelper
{

	/**
	 * @var \Memcached
	 */
	public $memcached;

	/**
	 * CacheHelper constructor.
	 * @param array $params
	 */
	public function __construct($host, $port)
	{
		$this->memcached = new \Memcached();
		$this->memcached->addServer($host, $port);
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * @param integer|null $expiration
	 * @return bool
	 */
	public function set($key, $value, $expiration = null)
	{
		return $this->memcached->set($key, $value, $expiration);
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public function get($key)
	{
		return $this->memcached->get($key);
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public function delete($key)
	{
		return $this->memcached->delete($key);
	}

}