<?php
/**
 * FecShop file.
 *
 * @link http://www.fecshop.com/
 * @copyright Copyright (c) 2016 FecShop Software LLC
 * @license http://www.fecshop.com/license/
 */
namespace fecshop\elasticsearch\models\elasticSearch;

use yii\elasticsearch\ActiveRecord;
use yii\base\InvalidConfigException;
/**
 * @author Terry Zhao <2358269014@qq.com>
 * @since 1.0
 */
class Product extends ActiveRecord
{
	protected $_attr;
    protected static $_lang;
    // elasticSearch 语言 analysis 分词器
    protected static $_lang_analysis;
    /**
     * 配置数组，语言简码 和 对应的es分词器名称analysis
     */
    public static $langAnalysis = [
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
        ];
    /**
     * Language Analyzers
     * A set of analyzers aimed at analyzing specific language text. The following types are supported: 
     * arabic, armenian, basque, bengali, brazilian, bulgarian, catalan, cjk, czech, danish, dutch, english, finnish, french, galician, german, greek, hindi, hungarian, indonesian, irish, italian, latvian, lithuanian, norwegian, persian, portuguese, romanian, russian, sorani, spanish, swedish, turkish, thai.
     * cjk : 中日韩
     * english :英语
     * french :法语
     * german :德语
     * italian :意大利语
     * portuguese :葡萄牙语
     * spanish :西班牙语
     * russian :俄语
     * dutch :荷兰语
     * brazilian :巴西语
     * 详细参看：https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-lang-analyzer.html#english-analyzer
     * 
     */
    public static function initLang($lang){
        $arr = self::$langAnalysis;
        if (isset($arr[$lang]) && $arr[$lang]) {
            self::$_lang = $lang;
            self::$_lang_analysis = $arr[$lang];
        }
    }
    /**
     * 主键
     */
    public static function primaryKey(){
        return ['_id'];
    }
    
    /**
     * es component
     */
	public static function getDb()
	{
		return \Yii::$app->get('elasticsearch');
	}
    
    /**
     * index ， 有一点类似数据库的index
     */
	public static function index()
	{
		if (!self::$_lang) {
            throw new InvalidConfigException('you must run func initLang($lang) first!');
        }
        return 'fecshop_product_'.self::$_lang;
	}
	/**
     * 获得属性
     */
	public function attributes()
    {
        
        if (!$this->_attr) {
            $mapConfig = self::mapConfig(true);
            $this->_attr = array_keys($mapConfig['properties']);
        }
        return $this->_attr;
    }
	/**
     * elasticsearch map config
     */
	public static function mapConfig($noAnalysis = false){
        if ($noAnalysis) {
            $analysis = '';
        } else {
            if (!self::$_lang_analysis) {
                throw new InvalidConfigException('you must run func initLang($lang) first!');
            }
            $analysis = self::$_lang_analysis;
        }
        return [
			'properties' => [
                'm_id'			        => ['type' => 'keyword',],
				//'product_id'			=> ['type' => 'string',"index" => "not_analyzed", "analyzer": $analysis],
				//'product_id'			=> ['type' => 'keyword',],
				'name'				    => ['type' => 'text', "analyzer" => $analysis],
				'short_description'	    => ['type' => 'text', "analyzer" => $analysis],
				'description'			=> ['type' => 'text', "analyzer" => $analysis],
				
                'spu'			        => ['type' => 'keyword'],
				'sku'	                => ['type' => 'keyword'],
				'score'	                => ['type' => 'integer'],
				'status'		        => ['type' => 'integer'],
				'is_in_stock'			=> ['type' => 'integer'],
				'url_key'		        => ['type' => 'keyword'],
				'price'		            => ['type' => 'float'],
				'cost_price'			=> ['type' => 'float'],
				'special_price'			=> ['type' => 'float'],
				'special_from'		    => ['type' => 'integer'],
				'special_to'	        => ['type' => 'integer'],
				'final_price'	        => ['type' => 'float'],
                'image'		            => ['type' => 'text', 'index' => false],
				'created_at'			=> ['type' => 'integer'],
				'sync_updated_at'		=> ['type' => 'integer'],
                
                'color'		            => ['type' => 'keyword',],  // 需要聚合的字段，需要加入：'fielddata': true
                'size'		            => ['type' => 'keyword',],
			]
		];
	}
	/**
     * mapping
     */
	public static function mapping()
    {
        return [
            static::type() => self::mapConfig(),
        ];
    }

    /**
     * Set (update) mappings for this model
     */
    public static function updateMapping(){
        $db = self::getDb();
        $command = $db->createCommand();
		if(!$command->indexExists(self::index())){
			$command->createIndex(self::index());
		}
        $command->setMapping(self::index(), self::type(), self::mapping());
    }
	/**
     * get mapping
     */
	public static function getMapping(){
		$db = self::getDb();
        $command = $db->createCommand();
		return $command->getMapping();
	}
	
    /**
     * 删除index
     */
    public static function deleteIndex()
    {
        $db = static::getDb();
        $command = $db->createCommand();
        $command->deleteIndex(static::index(), static::type());
    }
	
	
}