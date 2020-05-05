<?php
namespace ConnextPlugin;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Psr7\Response;

trait GuzzleHttp {

    private static $options = [
        RequestOptions::VERIFY => false,
        RequestOptions::TIMEOUT => 2
    ];
    private static $client = null;

    /**
     * 获取发送的数据
     * @param string $ptype
     * @param array  $params
     * @param string $dtype
     *
     * @return array
     */
    private static function getSendParams(string $ptype, array $params, string $dtype) : array
    {
        $ptype      = strtoupper($ptype);
        $dtype      = strtoupper($dtype);

        $sendParams = [];
        if ($ptype == 'GET') {
            $sendParams[RequestOptions::QUERY] = $params;
        }
        else {
            switch ($dtype) {
                case 'JSON':
                    $sendParams[RequestOptions::JSON] = $params;
                    break;
                case 'FORM':
                default:
                    $sendParams[RequestOptions::FORM_PARAMS] = $params;
                    break;
            }
        }
        return $sendParams;
    }

    /**
     * @param array $options
     * @return Client|null
     */
    private static function getClient(array $options)
    {
        if (self::$client) {
            return self::$client;
        }
        return self::$client = new Client(array_merge(self::$options, $options));
    }
    /**
     * @param array $params['method', 'data', 'type', 'url']
     * @param array $options 请求参数设置
     * @return bool|string
     */
    public static function httpRequest(array $params, array $options = [])
    {
        try{
            $client       = self::getClient($options);
            $sendData     = self::getSendParams($params['method'], $params['data'], $params['type']);
            $response     = $client->request($params['method'], $params['url'], $sendData);
            return $response->getBody()->getContents();
        }catch (\Throwable $ex){
            return false;
        }
    }

    /**
     * 实现批量请求
     * @param array $params['options' => ['method', 'type'], 'sendParams' => ['params'=>[],[]]]
     * @param array $options
     *
     * @return array
     */
    public static function batchRequest(array $params, array $options = [])
    {
        $client = new Client($options);
        $requests = self::getAsyncRequest($client, $params['options']);
        $responseData = [];
        $pool = new Pool($client, $requests($params['sendParams']), [
            'concurrency' => count($params['sendParams']),
            'fulfilled' => function (Response $response, $index) use (&$responseData) {
                $responseData[$index]['code'] = $response->getStatusCode();
                $responseData[$index]['content'] = $response->getBody()->getContents();
            },
            'rejected' => function (\Exception $e, $index) use (&$requestEntity, &$responseData) {

                $responseData[$index]['code'] = $e->getCode();
                $responseData[$index]['content'] = $e->getMessage();
            },
        ]);
        // 开始发送请求
        $promise = $pool->promise();
        $promise->wait();
        return $responseData;
    }

    /**
     * @param       $client
     * @param array $options
     *
     * @return callable
     */
    private static function getAsyncRequest(&$client, array &$options) : callable
    {
        return function ($sendParams) use ($client, $options) {
            foreach ($sendParams as $params) {
                yield function () use ($client, $params, $options) {
                    $sendData = self::getSendParams($options['method'], $params, $options['type']);
                    return $client->requestAsync($options['method'], $options['url'], $sendData);
                };
            }
        };
    }
}
