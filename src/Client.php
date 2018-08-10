<?php

namespace Algolia\AlgoliaSearch;

use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Algolia\AlgoliaSearch\Exceptions\TaskTooLongException;
use Algolia\AlgoliaSearch\Interfaces\ClientInterface;
use Algolia\AlgoliaSearch\Internals\ApiWrapper;
use Algolia\AlgoliaSearch\Internals\ClusterHosts;
use Algolia\AlgoliaSearch\RequestOptions\RequestOptions;
use Algolia\AlgoliaSearch\RequestOptions\RequestOptionsFactory;
use Algolia\AlgoliaSearch\Support\ClientConfig;
use Algolia\AlgoliaSearch\Support\Config;
use Algolia\AlgoliaSearch\Support\Helpers;

class Client implements ClientInterface
{
    /**
     * @var ApiWrapper
     */
    protected $api;

    public function __construct(ApiWrapper $apiWrapper)
    {
        $this->api = $apiWrapper;
    }

    public static function create($appId, $apiKey, $hosts = null)
    {
        $config = new ClientConfig(array(
            'appId' => $appId,
            'apiKey' => $apiKey,
            'hosts' => $hosts,
        ));

        return static::createWithConfig($config);
    }

    public static function createWithConfig(ClientConfig $config)
    {
        $hosts = $config->getHosts();
        if (!$hosts) {
            $hosts = ClusterHosts::createFromAppId($config->getAppId());
        } elseif (is_string($hosts)) {
            $hosts = new ClusterHosts(array($hosts));
        } elseif (is_array($hosts)) {
            $hosts = new ClusterHosts($hosts);
        }

        $apiWrapper = new ApiWrapper(
            Config::getHttpClient(),
            $config,
            $hosts
        );

        return new static($apiWrapper);
    }

    public function initIndex($indexName)
    {
        return new Index($indexName, $this->api);
    }

    public function setExtraHeader($headerName, $headerValue)
    {
        $this->api->setExtraHeader($headerName, $headerValue);
        return $this;
    }

    public function multipleQueries($queries, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['requests'] = $queries;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('requests', $queries);
        }

        return $this->api->read(
            'POST',
            api_path('/1/indexes/*/queries'),
            $requestOptions
        );
    }

    public function multipleBatchObjects($operations, $requestOptions = array())
    {
        Helpers::ensure_objectID($operations);

        if (is_array($requestOptions)) {
            $requestOptions['requests'] = $operations;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('requests', $operations);
        }

        return $this->api->write(
            'POST',
            api_path('/1/indexes/*/batch'),
            $requestOptions
        );
    }

    public function multipleGetObjects($requests, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['requests'] = $requests;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('requests', $requests);
        }

        return $this->api->write(
            'POST',
            api_path('/1/indexes/*/objects'),
            $requestOptions
        );
    }

    public function listIndexes($requestOptions = array())
    {
        return $this->api->read('GET', api_path('/1/indexes/'), $requestOptions);
    }

    public function moveIndex($srcIndexName, $destIndexName, $requestOptions = array())
    {
        return $this->api->write(
            'POST',
            api_path('/1/indexes/%s/operation', $srcIndexName),
            array(
                'operation' => 'move',
                'destination' => $destIndexName,
            ),
            $requestOptions
        );
    }

    // BC Break: ScopedCopyIndex was removed
    public function copyIndex($srcIndexName, $destIndexName, $requestOptions = array())
    {
        return $this->api->write(
            'POST',
            api_path('/1/indexes/%s/operation', $srcIndexName),
            array(
                'operation' => 'copy',
                'destination' => $destIndexName,
            ),
            $requestOptions
        );
    }

    public function clearIndex($indexName, $requestOptions = array())
    {
        return $this->initIndex($indexName)->clear($requestOptions);
    }

    public function deleteIndex($indexName, $requestOptions = array())
    {
        return $this->api->write(
            'DELETE',
            api_path('/1/indexes/%s', $indexName),
            array(),
            $requestOptions
        );
    }

    public function listApiKeys($requestOptions = array())
    {
        return $this->api->read('GET', api_path('/1/keys'), $requestOptions);
    }

    public function getApiKey($key, $requestOptions = array())
    {
        return $this->api->read('GET', api_path('/1/keys/%s', $key), $requestOptions);
    }

    public function addApiKey($keyParams, $requestOptions = array())
    {
        return $this->api->write('POST', api_path('/1/keys'), $keyParams, $requestOptions);
    }

    public function updateApiKey($key, $keyParams, $requestOptions = array())
    {
        return $this->api->write('PUT', api_path('/1/keys/%s', $key), $keyParams, $requestOptions);
    }

    public function deleteApiKey($key, $requestOptions = array())
    {
        return $this->api->write('DELETE', api_path('/1/keys/%s', $key), array(), $requestOptions);
    }

    // BC Break: signature was changed
    public static function generateSecuredApiKey($parentApiKey, $restrictions)
    {
        $urlEncodedRestrictions = Helpers::build_query($restrictions);

        $content = hash_hmac('sha256', $urlEncodedRestrictions, $parentApiKey).$urlEncodedRestrictions;

        return base64_encode($content);
    }

    public function searchUserIds($query, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['query'] = $query;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addBodyParameter('query', $query);
        }

        return $this->api->read('POST', api_path('/1/clusters/mapping/search'), $requestOptions);
    }

    public function listClusters($requestOptions = array())
    {
        return $this->api->read('GET', api_path('/1/clusters'), $requestOptions);
    }

    public function listUserIds($requestOptions = array())
    {
        return $this->api->read('GET', api_path('/1/clusters/mapping'), $requestOptions, array(
            'page' => 0,
            'hitsPerPage' => 20,
        ));
    }

    public function getUserId($userId, $requestOptions = array())
    {
        return $this->api->read('GET', api_path('/1/clusters/mapping/%s', $userId), $requestOptions);
    }

    public function getTopUserId($requestOptions = array())
    {
        return $this->api->read('GET', api_path('/1/clusters/mapping/%top'), $requestOptions);
    }

    public function assignUserId($userId, $clusterName, $requestOptions = array())
    {
        if (is_array($requestOptions)) {
            $requestOptions['X-Algolia-User-ID'] = $userId;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addHeader('X-Algolia-User-ID', $userId);
        }

        return $this->api->write(
            'POST',
            api_path('/1/clusters/mapping'),
            array(
                'cluster' => $clusterName,
            ),
            $requestOptions
        );
    }

    public function removeUserId($userId, $requestOptions = array())
    {

        if (is_array($requestOptions)) {
            $requestOptions['X-Algolia-User-ID'] = $userId;
        } elseif ($requestOptions instanceof RequestOptions) {
            $requestOptions->addHeader('X-Algolia-User-ID', $userId);
        }

        return $this->api->write(
            'DELETE',
            api_path('/1/clusters/mapping'),
            array(),
            $requestOptions
        );
    }

    public function getLogs($requestOptions = array())
    {
        return $this->api->read('GET', api_path('/1/logs'), $requestOptions, array(
            'offset' => 0,
            'length' => 10,
            'type' => 'all',
        ));
    }

    public function getTask($indexName, $taskId, $requestOptions = array())
    {
        $index = $this->initIndex($indexName);
        return $index->getTask($taskId, $requestOptions);
    }

    public function waitTask($indexName, $taskId, $requestOptions = array())
    {
        $index = $this->initIndex($indexName);
        return $index->waitTask($taskId, $requestOptions);
    }

    public function waitForKeyAdded($key, $requestOptions = array())
    {
        $retry = 1;
        $maxRetry = Config::$waitTaskRetry;

        do {
            try {
                $this->getApiKey($key, $requestOptions);
                return;
            } catch (NotFoundException $e) {
                // Try again
            }

            ++$retry;
            $factor = ceil($retry / 10);
            usleep($factor * 100000); // 0.1 second
        } while ($retry < $maxRetry);

        throw new TaskTooLongException("The key ".substr($key, 0, 6)."... isn't added yet.");
    }

    public function custom($method, $path, $requestOptions = array(), $hosts = null)
    {
        return $this->api->send($method, $path, $requestOptions, $hosts);
    }
}
