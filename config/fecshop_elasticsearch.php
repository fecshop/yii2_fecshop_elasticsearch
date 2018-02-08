<?php
/**
 * FecShop file.
 * @link http://www.fecshop.com/
 * @copyright Copyright (c) 2016 FecShop Software LLC
 * @license http://www.fecshop.com/license/
 */

return [
    /**
     * 下面是第三方扩展库包的配置方式
     */
    // 这个是扩展extensions的总开关，true代表打开
    'enable' => true, 
    // 各个入口的配置
    'app' => [
        // 1.公用层
        'common' => [
            // 在公用层的开关，设置成false后，公用层的配置将失效
            'enable' => true,
            // 公用层的具体配置下载下面
            'config' => [
                'components' => [
                    'elasticsearch' => [
                        'class' => 'yii\elasticsearch\Connection',
                        'nodes' => [
                            ['http_address' => '127.0.0.1:9200'],
                            // configure more hosts if you have a cluster
                        ],
                    ],
                ], 
                'services' => [
                    'search' => [
                        'childService' => [
                            'elasticSearch'  => [
                                'class'        => 'fecshop\elasticsearch\services\search\ElasticSearch',
                                'enableService'        => true,
                                'searchLang'  => [
                                    'en' => 'english',
                                    'fr' => 'french',
                                    'de' => 'german',
                                    'es' => 'spanish',
                                    'ru' => 'russian',
                                    'pt' => 'portuguese',
                                    'zh' => 'chinese',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        
        
        'console' => [
            // 在公用层的开关，设置成false后，公用层的配置将失效
            'enable' => true,
            // 公用层的具体配置下载下面
            'config' => [
                'controllerMap'=>[  
                    'elasticsearch'=>[  
                        'class'=>'fecshop\elasticsearch\controllers\ElasticsearchController'  
                    ],  
                ],

            ],
        ],
        
        
        
    ],
    
];









