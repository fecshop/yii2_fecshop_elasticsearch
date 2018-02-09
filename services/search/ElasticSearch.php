<?php
/**
 * FecShop file.
 *
 * @link http://www.fecshop.com/
 * @copyright Copyright (c) 2016 FecShop Software LLC
 * @license http://www.fecshop.com/license/
 */

namespace fecshop\elasticsearch\services\search;

use fecshop\services\search\SearchInterface;
use fecshop\services\Service;
use Yii;

/**
 * Search XunSearch Service.
 * @author Terry Zhao <2358269014@qq.com>
 * @since 1.0
 */
class ElasticSearch extends Service implements SearchInterface
{
    // Es搜索引擎支持的语言，也就是那些语言，使用Es搜索引擎。
    public $searchLang;
    // 匹配类型，目前使用的是 cross_fields, 其他的搜索类型详细，您可以参看: https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-multi-match-query.html#multi-match-types
    public $type = 'cross_fields';
    // 产品model
    protected $_productModelName = '\fecshop\models\mongodb\Product';
    protected $_productModel;
    // Es Product model
    protected $_searchModelName  = '\fecshop\elasticsearch\models\elasticSearch\Product';
    protected $_searchModel;
    
    /**
     * Es不同于Mongo等，他可以一次将搜索的产品列表，以及聚合属性以及属性的count，一次计算出来
     * 因此，在产品产品的函数中，就把聚合数据计算出来，存放到该变量
     * 后面的函数取聚合数据，直接从这个变量中取出来即可。
     */
    public $filter_values;
    
    public function init()
    {
        parent::init();
        list($this->_productModelName,$this->_productModel) = \Yii::mapGet($this->_productModelName); 
        list($this->_searchModelName,$this->_searchModel) = \Yii::mapGet($this->_searchModelName); 
    }
    /**
     * Mongodb初始化索引.Es不需要该函数，Es只需要新建mapping就可以了。
     */
    protected function actionInitFullSearchIndex()
    {
    }

    /**
     * 将产品信息同步到xunSearch引擎中.
     */
    protected function actionSyncProductInfo($product_ids, $numPerPage)
    {
        if (is_array($product_ids) && !empty($product_ids)) {
            $productPrimaryKey    = Yii::$service->product->getPrimaryKey();
            $elasticSearchModel       = new $this->_searchModelName();
            $filter['select']     = $elasticSearchModel->attributes();
            $filter['asArray']    = true;
            $filter['where'][]    = ['in', $productPrimaryKey, $product_ids];
            $filter['numPerPage'] = $numPerPage;
            $filter['pageNum']    = 1;
            $coll = Yii::$service->product->coll($filter);
            if (is_array($coll['coll']) && !empty($coll['coll'])) {
                foreach ($coll['coll'] as $one) {
                    $one_name = $one['name'];
                    $one_description = $one['description'];
                    $one_short_description = $one['short_description'];
                    if (!empty($this->searchLang) && is_array($this->searchLang)) {
                        foreach ($this->searchLang as $langCode => $langName) {
                            //echo $langCode;
                            $one['_id'] = (string) $one['_id'];
                            // yii2 elasticSearch bug问题进行的处理
                            $one['m_id'] = (string) $one['_id'];
                            $this->_searchModel::initLang($langCode);
                            $elasticSearchModel = $this->_searchModel->findOne($one['_id']);
                            if (!$elasticSearchModel['sku']) {
                                $elasticSearchModel = new $this->_searchModelName();
                                $elasticSearchModel::initLang($langCode);
                            } 
                            //$elasticSearchModel->_id = (string) $one['_id'];
                            $one['name'] = Yii::$service->fecshoplang->getLangAttrVal($one_name, 'name', $langCode);
                            $one['description'] = Yii::$service->fecshoplang->getLangAttrVal($one_description, 'description', $langCode);
                            $one['short_description'] = Yii::$service->fecshoplang->getLangAttrVal($one_short_description, 'short_description', $langCode);
                            $one['sync_updated_at'] = time();
                            $serialize = true;
                            Yii::$service->helper->ar->save($elasticSearchModel, $one, $serialize);
                            if ($errors = Yii::$service->helper->errors->get()) {
                                // 报错。
                                echo  $errors;
                                //return false;
                            }
                        }
                    }
                }
            }
        }
        //echo "XunSearch sync done ... \n";
        
        return true;
    }
    
    // 废弃
    protected function actionDeleteNotActiveProduct($nowTimeStamp)
    {
    }

    /**
     * 删除在EsSearch的所有搜索数据，
     * 当您的产品有很多产品被删除了，但是在Es存在某些异常没有被删除
     * 您希望也被删除掉，那么，你可以通过这种方式批量删除掉产品
     * 然后重新跑一边同步脚本.
     */
    protected function actionEsDeleteAllProduct()
    {
        if (!empty($this->searchLang) && is_array($this->searchLang)) {
            foreach ($this->searchLang as $langCode => $langName) {
                $this->_searchModel::initLang($langCode);
                $this->_searchModel::deleteIndex();
            }
        }
    }

    /** 
     * @property $select | Array ， 搜索的字段
     * @property $where | Array ，搜索的条件
     * @property $pageNum | 页数
     * @property $numPerPage | 每页的产品数
     * @property $product_search_max_count | int ，最大搜索的个数，搜索引擎是没有分页概念的，只有一次查出来所有结果，因此需要限制搜索的最大数
     * @property $filterAttr | Array，聚合的字段
     * 得到搜索的产品列表.
     */
    protected function actionGetSearchProductColl($select, $where, $pageNum, $numPerPage, $product_search_max_count, $filterAttr)
    {
        $collection = $this->fullTearchText($select, $where, $pageNum, $numPerPage, $product_search_max_count, $filterAttr);
        $collection['coll'] = Yii::$service->category->product->convertToCategoryInfo($collection['coll']);
        
        return $collection;
    }
    /**
     *  全文索引，参数这里不一一介绍，和函数 actionGetSearchProductColl的参数是一样的
     */
    protected function fullTearchText($select, $where, $pageNum, $numPerPage, $product_search_max_count, $filter_attrs)
    {
        $lang = Yii::$service->store->currentLangCode;
        $this->_searchModel->initLang($lang);
        $searchText = $where['$text']['$search'];
        $productM = Yii::$service->product->getBySku($searchText);
        $productIds = [];
        // 如果通过sku直接可以查询到，那么，代表数据的是sku，直接返回数据即可。
        if ($productM) {
            $productIds[] = $productM['_id'];
        } else {
            // 如果不是sku，那么根据下面的步骤进行查询
            // https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-multi-match-query.html
            // https://www.elastic.co/guide/cn/elasticsearch/guide/current/multi-query-strings.html#prioritising-clauses
            $query_arr = [];
            // 组织where条件。
            if (is_array($where) && !empty($where)) {
                if (isset($where['$text']['$search']) && $where['$text']['$search']) {
                    $query_arr['bool']['must'] = [
                        // https://www.elastic.co/guide/en/elasticsearch/guide/current/multi-match-query.html
                        // https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-multi-match-query.html
                        [
                            'multi_match' => [
                                'query'     => $where['$text']['$search'],
                                'type'      => $this->type,  //default  best_fields, see: https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-multi-match-query.html#multi-match-types
                                'fields'    =>    [ "name^4", "description^2" ],  // ^后面是这个属性的权重。 see: https://www.elastic.co/guide/en/elasticsearch/guide/current/multi-match-query.html#_boosting_individual_fields
                                'operator'  =>  'and',
                                'tie_breaker' => 0.3 ,
                                //"minimum_should_match" => "0%",
                            ],
                        ]
                    ];
                } else {
                    return [];
                }
                $queryMust = [];
                foreach ($where as $k => $v) {
                    if ($k != '$text') {
                        // 数组类型，代表的是范围类型，譬如价格，下面是进行的一系列的字符转换。
                        if (is_array($v)) {
                            $ar = [];
                            foreach($v as $k1 => $v1) {
                                $k1 = str_replace('$','',$k1);
                                $ar[$k1] = $v1;
                            }
                            $queryMust[] = [
                                'range' => [$k => $ar]
                            ];
                        } else {
                            $queryMust[] = [
                                'term' => [$k => $v]
                            ];
                        }
                    }
                }
                if (!empty($queryMust)) {
                    $query_arr['bool']['filter'] = $queryMust;
                }
            }
            $searchQuery = $this->_searchModel->find()->asArray()->query($query_arr);
            // 设置最大查询数
            $size = $product_search_max_count;  // 5000;
            // 设置aggregate部分
            foreach ($filter_attrs as $filter_attr) {
                $type = 'terms';
                $options = [
                  'field' => $filter_attr,
                  'size'  => $size,
                ];
                $searchQuery->addAgg($filter_attr, $type, $options);
            }
            // 得到查询结果
            $search_data = $searchQuery->createCommand()->search();
            // 根据上面的查询结果，得到过滤的部分 - aggregate 部分，。
            $agg_data = $search_data['aggregations'];
            if (is_array($agg_data)) {
                foreach ($agg_data as $f_attr => $filter) {
                    $arr = [];
                    $buckets = $filter['buckets'];
                    if (is_array($buckets)) {
                        foreach ($buckets as $o) {
                            $k = $o['key'];
                            $count = $o['doc_count'];
                            // $arr[$k] = $count;
                            $arr[] = [
                                '_id' => $k,
                                'count' => $count,
                            ];
                        }
                    }
                    $this->filter_values[$f_attr] = $arr;
                }
            }
            // 根据上面的查询结果，得到产品数据部分
            $productData = [];
            if (is_array($search_data['hits']['hits'])) {
                foreach ($search_data['hits']['hits'] as $one) {
                    $_id = $one['_id'];
                    $productOne = $one['_source'];
                    $productOne['_id'] = $_id;
                    $productData[] = $productOne;
                }
            }
            $data = [];
            // 产品相同spu的产品，只显示一个。
            foreach ($productData as $one) {
                if (!isset($data[$one['spu']])) {
                    $data[$one['spu']] = $one;
                    //$data['_id'] = $one->getPrimaryKey();
                }
            }
            $count = count($data);
            $offset = ($pageNum - 1) * $numPerPage;
            $limit = $numPerPage;
            $productIds = [];
            foreach ($data as $d) {
                $productIds[] = new \MongoDB\BSON\ObjectId($d['_id']);
            }
            // 最终得到产品id的数组。
            $productIds = array_slice($productIds, $offset, $limit);
        }
        // 根据上面查询得到的product_ids数组，去mongodb中查询产品。
        if (!empty($productIds)) {
            $query = $this->_productModel->find()->asArray()
                    ->select($select)
                    ->where(['_id'=> ['$in'=>$productIds]]);
            $data = $query->all();
            /**
             * 下面的代码的作用：将结果按照上面in查询的顺序进行数组的排序，使结果和上面的搜索结果排序一致（_id）。
             */
            $s_data = [];
            foreach ($data as $one) {
                $_id = (string) $one['_id'];
                if($_id){
                    $s_data[$_id] = $one;
                }
            }
            $return_data = [];
            foreach ($productIds as $product_id) {
                $pid = (string) $product_id;
                if (isset($s_data[$pid]) && $s_data[$pid]) {
                    $return_data[] = $s_data[$pid];
                }
            }
            return [
                'coll' => $return_data,
                'count'=> $count,
            ];
        }
    }
    /** 
     * @property $filter_attr | string  ， 聚合的属性字段
     * @property $where | Array ， 查询条件
     * @return Array， 得到搜索的sku列表侧栏的过滤.example：
     * [
     *        [ '_id' => $k, 'count' => $v,],
     *        [ '_id' => $k, 'count' => $v,],
     *        [ '_id' => $k, 'count' => $v,],
     * ]
     * 下面的$this->filter_values，该类变量在上面的查询中已经被初始化，这里直接调用即可。
     */
    protected function actionGetFrontSearchFilter($filter_attr, $where)
    {
        if (isset($this->filter_values[$filter_attr])) {
            return $this->filter_values[$filter_attr];
        } else {
            return [];
        }
    }

    /**
     * @property $product_id | String ，产品id
     * 通过product_id删除搜索数据.
     */
    protected function actionRemoveByProductId($product_id)
    {
        if (is_object($product_id)) {
            $product_id = (string) $product_id;
            $model = $this->_searchModel->findOne($product_id);
            if($model){
                $model->delete();
            }
        }
    }
    /**
     * 更新ElasticSearch product部分的Mapping
     * 关于elasticSearch的mapping，参看：https://www.elastic.co/guide/en/elasticsearch/reference/current/mapping.html
     */
    public function updateMapping(){
        if (!empty($this->searchLang) && is_array($this->searchLang)) {
            foreach ($this->searchLang as $langCode => $langName) {
                $this->_searchModel::initLang($langCode);
                $this->_searchModel->updateMapping();
            }
        }
        
        
    }
}
