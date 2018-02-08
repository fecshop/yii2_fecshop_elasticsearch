<?php
/**
 * FecShop file.
 *
 * @link http://www.fecshop.com/
 * @copyright Copyright (c) 2016 FecShop Software LLC
 * @license http://www.fecshop.com/license/
 */

namespace fecshop\elasticsearch\services\search;

//use fecshop\models\mongodb\Product;
//use fecshop\models\xunsearch\Search as XunSearchModel;
use fecshop\services\Service;
use Yii;

/**
 * Search XunSearch Service.
 * @author Terry Zhao <2358269014@qq.com>
 * @since 1.0
 */
class ElasticSearch extends Service implements \fecshop\services\search\SearchInterface
{
    public $searchIndexConfig;
    public $searchLang;
    public $fuzzy = false;
    public $synonyms = false;
    protected $_productModelName = '\fecshop\models\mongodb\Product';
    protected $_productModel;
    protected $_searchModelName  = '\fecshop\elasticsearch\models\elasticSearch\Product';
    protected $_searchModel;
    
    public $filter_values;
    
    public function init()
    {
        parent::init();
        list($this->_productModelName,$this->_productModel) = \Yii::mapGet($this->_productModelName); 
        list($this->_searchModelName,$this->_searchModel) = \Yii::mapGet($this->_searchModelName); 
    }
    /**
     * 初始化索引.
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
                
                /**
                 * $EsSearchData = $this->_searchModel->find()
                 *     ->limit($numPerPage)  
                 *     ->offset(($i - 1) * $numPerPage)
                 *     ->all();
                 * foreach ($EsSearchData as $one) {
                 *     $one->delete();
                 * }
                 */
            }
        }
    }

    /** 未
     * 得到搜索的产品列表.
     */
    protected function actionGetSearchProductColl($select, $where, $pageNum, $numPerPage, $product_search_max_count, $filterAttr)
    {
        $collection = $this->fullTearchText($select, $where, $pageNum, $numPerPage, $product_search_max_count, $filterAttr);

        $collection['coll'] = Yii::$service->category->product->convertToCategoryInfo($collection['coll']);
        //var_dump($collection);
        //exit;
        return $collection;
    }
    /**
     *  未
     */
    protected function fullTearchText($select, $where, $pageNum, $numPerPage, $product_search_max_count, $filter_attrs)
    {
        //$filter_attrs[] = 'spu';
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
                                //'type'      => '',  //default  best_fields, see: https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-multi-match-query.html#multi-match-types
                                'fields'    =>    [ "name^10", "description^4" ],  // ^后面是这个属性的权重。 see: https://www.elastic.co/guide/en/elasticsearch/guide/current/multi-match-query.html#_boosting_individual_fields
                                'operator'  =>  'and',
                                'tie_breaker' => 0.3 ,
                                //"minimum_should_match" => "0%",
                            ],
                        ]
                        /*
                        [
                            'match' => [
                                'name' => [
                                    'query' => $where['$text']['$search'],
                                    'boost' => 2, // 搜索权重
                                ]
                            ]
                        ],
                        [
                            'match' => [
                                'description' => [
                                    'query' => $where['$text']['$search'],
                                    'boost' => 1, // 搜索权重
                                ]
                            ]
                        ],
                        */
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
            //var_dump($query_arr);
            $searchQuery = $this->_searchModel->find()->asArray()->query($query_arr);
            
            // 侧栏过滤的属性。
            //$filter_attrs = ['price'];
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
            //var_dump($search_data);
            /*
            $data = $this->_searchModel->find()
                            ->asArray()
                            ->query($query_arr)
                            ->addAgg($name, $type, $options)
                            ->createCommand()
                            ->search();
            */
            
            // aggregate 部分，得到过滤的部分。
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
            // var_dump($this->filter_values);
            // 产品数据部分
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

    /** 未
     * 得到搜索的sku列表侧栏的过滤.
     $count_arr[] = [
                    '_id' => $k,
                    'count' => $v,
                ];
     */
    protected function actionGetFrontSearchFilter($filter_attr, $where)
    {
        if (isset($this->filter_values[$filter_attr])) {
            return $this->filter_values[$filter_attr];
        } else {
            return [];
        }
        /*
        return [];
        $lang = Yii::$service->store->currentLangCode;
        $this->_searchModel->initLang($lang);
        $searchText = $where['$text']['$search'];
        if (!$searchText) {
            return [];
        }
        $query_arr = [];
        if (is_array($where) && !empty($where)) {
            if (isset($where['$text']['$search']) && $where['$text']['$search']) {
                $query_arr['bool']['should'] = [
                    [
                        'match' => [
                            'name' => [
                                'query' => $where['$text']['$search'],
                                'boost' => 2, // 搜索权重
                            ]
                        ]
                    ],
                    [
                        'match' => [
                            'description' => [
                                'query' => $where['$text']['$search'],
                                'boost' => 1, // 搜索权重
                            ]
                        ]
                    ],
                ];
            } else {
                return [];
            }
            $queryMust = [];
            foreach ($where as $k => $v) {
                if ($k != '$text') {
                    $queryMust[] = [
                        'match' => [$k => $v]
                    ];
                }
            }
            if (!empty($queryMust)) {
                $query_arr['bool']['must'] = $queryMust;
            }
        }
        $filter_attr = 'price';
        $size = 5000;
        $name = $filter_attr;
        $type = 'terms';
        $options = [
          'field' => $filter_attr,
          'size'  => $size,
        ];
        $data = $this->_searchModel->find()
                        ->asArray()
                        ->query($query_arr)
                        ->addAgg($name, $type, $options)
                        ->createCommand()
                        ->search();
        $agg_data = $data['aggregations'];
        
        
        
        var_dump($data);exit;
        $buckets  = $agg_data[$filter_attr]['buckets'];
        var_dump($buckets);exit;
        $country_code_arr = \yii\helpers\BaseArrayHelper::getColumn($buckets,'key');
        var_dump($country_code_arr);
        exit;
        
        
        
        $searchQuery->addStatisticalFacet('sku', ['field' => 'sku']);
        
        
        $data = $searchQuery->search();
        echo 222;
        var_dump($data);
        */
        
        /*
        $productData = [];
        
        
        //var_dump($where);
        $dbName = $this->_searchModel->projectName();
        $_search = Yii::$app->xunsearch->getDatabase($dbName)->getSearch();
        $text = isset($where['$text']['$search']) ? $where['$text']['$search'] : '';
        if (!$text) {
            return [];
        }
        $sh = '';
        foreach ($where as $k => $v) {
            if ($k != '$text') {
                if (!$sh) {
                    $sh = ' AND '.$k.':'.$v;
                } else {
                    $sh .= ' AND '.$k.':'.$v;
                }
            }
        }
        //echo $sh;

        $docs = $_search->setQuery($text.$sh)
            ->setFacets([$filter_attr])
            ->setFuzzy($this->fuzzy)
            ->setAutoSynonyms($this->synonyms)
            ->search();
        $filter_attr_counts = $_search->getFacets($filter_attr);
        $count_arr = [];
        if (is_array($filter_attr_counts) && !empty($filter_attr_counts)) {
            foreach ($filter_attr_counts as $k => $v) {
                $count_arr[] = [
                    '_id' => $k,
                    'count' => $v,
                ];
            }
        }

        return $count_arr;
        */
    }

    /**
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
    
    public function updateMapping(){
        if (!empty($this->searchLang) && is_array($this->searchLang)) {
            foreach ($this->searchLang as $langCode => $langName) {
                $this->_searchModel::initLang($langCode);
                $this->_searchModel->updateMapping();
            }
        }
        
        
    }
}
