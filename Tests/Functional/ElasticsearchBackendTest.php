<?php
declare(strict_types=1);

namespace TeamNeusta\Elasticcache\Tests\Functional;

use Elastica\Client;
use Elastica\Exception\NotFoundException;
use Elastica\Query\MatchAll;
use TYPO3\CMS\Core\Cache\CacheManager;

class ElasticsearchBackendTest extends \PHPUnit_Framework_TestCase
{

    protected $cacheConfiguration;
    /**
     * @var Client
     */
    protected $client;

    public function setUp()
    {
        $this->cacheConfiguration = [ 'functesting' => [
            'backend'  => 'Neusta\\Elasticcache\\Cache\\Backend\\ElasticsearchBackend',
            'frontend' => 'TYPO3\\CMS\\Core\\Cache\\Frontend\\StringFrontend',
            'options'  => [
                'indexName' => 'functest',
            ],
        ]];

        $this->client = new Client();
    }

    public function tearDown()
    {
        $this->client->getIndex('functest')->delete();
    }
    /**
     * @test
     * @return void
     */
    public function elasticIndexWillBeCreatedIfItDoesNotExist()
    {
        $index = $this->client->getIndex('functest');
        $this->assertFalse($index->exists());

        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations($this->cacheConfiguration);
        $cacheManager->getCache('functesting');

        $this->assertTrue($index->exists());
    }

    /**
     * @test
     */
    public function setAndGetAddEntryToTheCacheAndRetrieveIt()
    {
        $cache = $this->createCache();

        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 0);
        $entry = $cache->get('my_test_identifier');

        $this->assertSame('mytestdata', $entry);
    }

    /**
     * @test
     * @return void
     */
    public function getEntryReturnsFalseIfNoEntryIsFound()
    {
        $cache = $this->createCache();
        $entry = $cache->get('not_found_identifier');

        $this->assertFalse($entry);
    }

    /**
     * @test
     * @return void
     */
    public function getEntryReturnsFalseIfLifetimeOfEntryIsLessThanNow()
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        // wait for entry to expire
        sleep(2);
        $entry = $cache->get('my_test_identifier');

        $this->assertFalse($entry);
    }

    /**
     * @test
     * @return void
     */
    public function hasReturnsFalseIfNoEntryIsFound()
    {
        $cache = $this->createCache();
        $entry = $cache->has('not_found_identifier');

        $this->assertFalse($entry);
    }

    /**
     * @test
     * @return void
     */
    public function hasReturnsFalseIfLifeTimeOfEntryIsLessThanNow()
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        // wait for entry to expire
        sleep(2);
        $entry = $cache->has('my_test_identifier');

        $this->assertFalse($entry);
    }

    /**
     * @test
     * @return void
     */
    public function removeDeletesEntryFromCache()
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        $cache->remove('my_test_identifier');

        $this->expectException(NotFoundException::class);

        $this->client->getIndex('functest')->getType('cacheEntry')->getDocument('my_test_identifier');
    }

    /**
     * @test
     * @return void
     */
    public function flushRemovesAllCacheEntries()
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier1', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier2', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier3', 'mytestdata', ['test_tag'], 1);
        sleep(1);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(4, $count);

        $cache->flush();
        sleep(2);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(0, $count);
    }


    /**
     * @test
     * @return void
     */
    public function collectGarbageDeletesExpiredCacheEntries()
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier1', 'mytestdata', ['test_tag'], 1500);
        $cache->set('my_test_identifier2', 'mytestdata', ['test_tag'], 1500);
        $cache->set('my_test_identifier3', 'mytestdata', ['test_tag'], 1);
        sleep(1);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(4, $count);

        $cache->collectGarbage();
        sleep(1);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(2, $count);
    }

    /**
     * @test
     * @return void
     */
    public function flushByTagRemovesEntriesWithSpecifiedTag()
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier1', 'mytestdata', ['test_tag2'], 1);
        $cache->set('my_test_identifier2', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier3', 'mytestdata', ['test_tag2'], 1);
        sleep(2);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(4, $count);

        $cache->flushByTag('test_tag');
        sleep(2);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(2, $count);
    }

    /**
     * @test
     * @return void
     */
    public function findIdentifiersByTagReturnsTaggedDocumentIdentifiers()
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier1', 'mytestdata', ['test_tag2'], 1);
        $cache->set('my_test_identifier2', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier3', 'mytestdata', ['test_tag2'], 1);
        sleep(1);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(4, $count);

        $identifiers = $cache->getBackend()->findIdentifiersByTag('test_tag');

        $expected = [
            'my_test_identifier',
            'my_test_identifier2',
        ];
        self::assertSame($expected, $identifiers);
    }

    /**
     * @return \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface
     */
    protected function createCache(): \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface
    {
        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations($this->cacheConfiguration);
        $cache = $cacheManager->getCache('functesting');
        return $cache;
    }
}
