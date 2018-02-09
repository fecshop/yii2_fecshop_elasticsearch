Fecshop ElasticSearch
======================

> fecshop elasticsearch 功能部分，用于将分类，产品，搜索页面，底层使用elasticSearch支持


### 环境安装

1.安装elasticSearch 6.1

http://www.fecshop.com/topic/672

2.安装elasticSearch 可视化工具 kibana

http://www.fecshop.com/topic/668


### 安装fecshop elasticSearch扩展，也就是本扩展

1.安装

```
composer require --prefer-dist fancyecommerce/fecshop_elasticsearch 
```

or 在根目录的`composer.json`中添加

```
"fancyecommerce/fecshop_elasticsearch": "~1.xx"  // 使用最新版本号 ，在这里查看：https://github.com/fecshop/yii2_fecshop_elasticsearch/releases 

```

然后执行

```
composer update
```

### yii2-elasticSearch 扩展bug处理

yii2-elasticSearch目前还不支持 ElasticSearch 6 的处理，会有问题，而且yii2项目组好像很忙：https://github.com/yiisoft/yii2-elasticsearch/issues/167#issuecomment-364055614
, 因此对其进行了略微改动（比较粗暴的改动，仅支持本插件）

1.在根目录`composer.json`中添加
`"yiisoft/yii2-elasticsearch": "2.1@dev",`
,然后执行`composer update`

2.更新后，然后将 vendor/fancyecommerce/fecshop_elasticsearch/yii2-elasticSearch
下的三个php文件覆盖到`/vendor/yiisoft/yii2-elasticsearch` 下即可

3.当yii2-elasticSearch 项目组修复了这个问题后，此处将不需要执行（这个只能等官方了）

### 配置

1.添加当前扩展的配置到fecshop

将 `vendor/fancyecommerce/fecshop_elasticsearch/config/fecshop_elasticsearch.php`文件复制到 `common/config/fecshop_third_extensions/`下面
，然后打开这个文件

1.1在 `nodes` 处配置`ip`和`port`,配置ES的连接

1.2在`searchLang`处，配置支持的语言，也就是把您的网站的语言都填写过来，那么，这些语言就会使用
`elasticSearch`搜索。 


2.将mongodb和xunsearch搜索的语言改为elasticSearch

打开文件 `common/config/fecshop_local_services/Search.php`

将 mongodb 和 xunsearch 部分的搜索语言部分注释掉，
如果您想要某些语言继续使用mongodb或xunsearch搜索，那么可以保留某些语言，
各个搜索引擎的`searchLang`中的语言都是唯一的，不要一种语言出现在2个搜索引擎里面

```
    // mongodb
    /*
    'searchLang'  => [
        'en' => 'english',
        'fr' => 'french',
        'de' => 'german',
        'es' => 'spanish',
        'ru' => 'russian',
        'pt' => 'portuguese',
    ],
    */
    
    // xunsearch
    /*
    'searchLang'    => [
        'zh' => 'chinese',
    ],
    */

```

3.初始化数据

fecshop 根目录下执行

3.1、新建elasticSearch的mapping（必须执行）

```
./yii elasticsearch/updatemapping
```

3.2、删除es的产品index（当您的mapping中的某个字段需要修改，直接修改是无效的，只能删除index库，然后重建）

```
./yii elasticsearch/clean
```

3.3、同步产品到elasticSearch（必须执行）

```
cd vendor/fancyecommerce/fecshop/shell/search/
sh fullSearchSync.sh
```

3.4、然后，es部分就可以访问了

3.5、当您的产品信息有改动的时候，产品保存的时候，会自动同步到ES的

### 扩展


搜索的model路径为： `vendor/fancyecommerce/fecshop_elasticsearch/models/elasticSearch/Product.php`
，如果您想更改里面的内容，您可以重写这个model，进行更改，
重写model可以参看：

[通过rewriteMap进行重写Block Model 层](http://www.fecshop.com/doc/fecshop-guide/develop/cn-1.0/guide-fecshop-rewrite-func.html#8rewritemapblock-model)


### 备注

1.支持的语言

https://github.com/fecshop/yii2_fecshop_elasticsearch/blob/master/models/elasticSearch/Product.php

暂时支持这些语言


```
'zh' => 'cjk', // 中国
'kr' => 'cjk', // 韩国
'jp' => 'cjk', // 日本
'en' => 'english', //
'fr' => 'french', 
'de' => 'german',
'it' => 'italian',
'pt' => 'portuguese',
'es' => 'spanish',
'ru' => 'russian',
'nl' => 'dutch',
'br' => 'brazilian', 
```

Es6默认就支持的analysis，详细参看：https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-lang-analyzer.html#english-analyzer

2.产品搜索index

一个语言一个index（elasticSearch的index，有一点点类似mysql的数据库，type，一点点类似表
，不过完全不同。）


