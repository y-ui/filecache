filecache php 文件缓存类
=========

* 支持key=>value
* 支持片段缓存

##使用方法

```php
include 'filecache.php';
$cache = new FileCache();

//数据缓存
$cache->set('test', 'file cache test', 3600); // key, value, expired
$cache->get('test');
$cache->delete('test');
 
//片段缓存
if($cache->startCache('html', 3600)) // key, expired
{
  缓存内容…… 任意输出
  
  $cache->endCache();
}
```

##配置
```php


/**
* @param string $path 缓存文件存放路径
* @param int $max_path 缓存的最大目录数
* @param int $max_file 缓存最大文件数
* @param int $gc_probality 执行set时遍历缓存以删除过期缓存数据操作的执行概率 百万分之 *
*/
$cache = new FileCache($path = "cache", $max_path = 100, $max_file = 50000, $gc_probality = 100);
```
