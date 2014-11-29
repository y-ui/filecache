<?php
/**
* 文件缓存类
* 支持数据缓存和片段缓存
*
* @author liuzhengwei
* @since 2014-11-29
* 
* 使用方法
* $c = new FileCache();
* //key => value 缓存
* $c->set('test', 'filecache test', 100); //key, value, expired
* $c->get('test');
* $c-delete('test');
*
* //片段缓存
* if ($c->startCache('html', 3600))  //key, expired
* {
* 	任意输出内容
* 	$c->endCache();
* }
*
*/

class FileCache
{
	/**
	* 缓存文件存放路径
	*/
	public $path;

	/**
	* 缓存存放目录数
	*/
	public $max_path;

	/**
	* 最多缓存多少个文件，按需配置，此值越大，GC消耗时间越久
	* 此值对cache命中率影响非常小，几乎可以忽略
	*/
	public $max_file;

	/**
	* GC 执行概率 百万分之 *
	*/
	public $gc_probality;

	private $basepath;

	public function __construct($path = "cache", $max_path = 100, $max_file = 50000, $gc_probality = 100)
	{
		$this->path = $path;
		$this->max_path = $max_path;
		$this->max_file = $max_file;
		$this->gc_probality = $gc_probality;
		$this->basepath = realpath($this->path) . DIRECTORY_SEPARATOR;
	}

	/**
	* 设置缓存
	*
	* @param string $key 	保存的key,操作数据的唯一标识，不可重复
	* @param value $val 	数据内容，可以是int/string/array/object/Boolean 其他没测过，如有需求自行测试
	* @param int $expired 	过期时间，不设默认为一年
	* @return bool
	*/
	public function set($key, $val, $expired = 31536000)
	{
		if (rand(0, 1000000) < $this->gc_probality) $this->gc();

		$key = strval($key);
		$cache_file = $this->_getCacheFile($key);

		$data = unserialize(file_get_contents($cache_file));
		empty($data[$key]) && $data[$key] = array();

		$data[$key]['data'] = $val;
		$data[$key]['expired'] = time() + $expired;

		file_put_contents($cache_file, serialize($data), LOCK_EX) or die("写入文件失败");
		return @touch($cache_file, $data[$key]['expired']);
	}

	/**
	* 获得保存的缓存
	*
	* @param string $key 	key,操作数据的唯一标识
	* @return null/data
	*/
	public function get($key)
	{
		$key = strval($key);
		$cache_file = $this->_getCacheFile($key);
		$val = @file_get_contents($cache_file);

		if (!empty($val))
		{
			$val = unserialize($val);
			if (!empty($val) && isset($val[$key]))
			{
				$data = (array) $val[$key];
				if ($data['expired'] < time())
				{
					$this->delete($key);
					return null;
				}
				return $data['data'];
			}
		}
		return null;
	}

	/**
	* 开始片段缓存
	* 必须配合endCache使用
	*
	* @param string $key 	保存的key,操作数据的唯一标识，不可重复
	* @param int $expired 	过期时间，不设默认为一年
	* @return bool
	*/
	public function startCache($key, $expired = 31536000)
	{
		$data = $this->get($key);
		if (!empty($data))
		{
			print $data;
			return false;
		}
		else
		{
			ob_start();
			print $key . "||" . $expired . "filecache_used::]";
			return true;
		}
	}

	/**
	* 结束片段缓存
	*
	* @return bool
	*/
	public function endCache()
	{
		$data = ob_get_contents();
		ob_end_clean();
		preg_match("/(.*?)filecache_used::]/is", $data, $key);
		if (empty($key[1]))
		{
			return null;
		}
		$data = str_replace($key[0], '', $data);
		$t = explode("||", $key[1]);
		$key = $t[0];
		$expired = $t[1];
		$this->set($key, $data, $expired);
		print $data;
	}

	/**
	* 删除缓存
	*
	* @param string $key 	保存的key,操作数据的唯一标识，不可重复
	* @return bool
	*/
	public function delete($key)
	{
		$key = strval($key);
		$cache_file = $this->_getCacheFile($key);

		$data = unserialize(file_get_contents($cache_file));
		unset($data[$key]);
		if (empty($data))
		{
			return @unlink($cache_file);
		}
		file_put_contents($cache_file, serialize($data), LOCK_EX);
		return true;
	}

	/**
	* 缓存回收机制
	* 遍历所有缓存文件，删除已过期文件，如果缓存文件存在不止一个缓存数据，照删不务……
	* TODO 这里之前用hashtable做了数据索引，GC时间会比遍历快30%左右，但是会拖慢set和get的时间，据我测试set会慢一倍！
	*
	* @param string $path 缓存目录
	* @return void
	*/
	public function gc($path = null)
	{
		if($path === null) $path = $this->basepath;
		if(($handle = opendir($path)) === false) return;
		while(($file = readdir($handle)) !== false)
		{
			if($file[0] === '.') continue;
			$fullPath = $path . DIRECTORY_SEPARATOR . $file;
			if(is_dir($fullPath))
			{
				$this->gc($fullPath);
			}
			elseif(@filemtime($fullPath) < time())
			{
				@unlink($fullPath);
			}
		}
		closedir($handle);
	}


	private function _getCacheFile($key)
	{
		$hash = $this->hash32($key);
		$path = $this->basepath . $this->_getPathName($hash);
		$file = $path . DIRECTORY_SEPARATOR . $this->_getCacheFileName($hash);
		if (!file_exists($path))
		{
			mkdir($path, 0777);
		}
		if (!file_exists($file))
		{
			$handler = fopen($file, 'w');
			fclose($handler);
		}
		return $file;
	}

	private function _getPathName($hash)
	{
		return $hash % $this->max_path;
	}

	private function _getCacheFileName($hash)
	{
		return $hash % $this->max_file;
	}

	private function hash32($str) 
	{ 
	    return crc32($str) >> 16 & 0x7FFFFFFF; 
	} 
}