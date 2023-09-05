<?php
declare(strict_types=1);

namespace TeamNeusta\Elasticcache\Tests\Functional;

use Elastica\Client;
use Elastica\Exception\NotFoundException;
use Elastica\Query\MatchAll;
use PHPUnit\Framework\TestCase;
use TeamNeusta\Elasticcache\Cache\Backend\ElasticsearchBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

class ElasticsearchBackendTest extends TestCase
{
    /**
     * @var array[]
     */
    protected array $cacheConfiguration;
    protected Client $client;

    public function setUp(): void
    {
        $this->cacheConfiguration = [
            'functesting' => [
                'backend'  => ElasticsearchBackend::class,
                'frontend' => 'TYPO3\\CMS\\Core\\Cache\\Frontend\\StringFrontend',
                'options'  => [
                    'indexName'          => 'functest',
                    'indexConfiguration' => 'EXT:elasticcache/Tests/Fixtures/indexConfig.yaml'
                ],
            ]
        ];

        $this->client = new Client();
    }

    public function tearDown(): void
    {
        $this->client->getIndex('functest')->delete();
    }

    /**
     * @test
     */
    public function elasticIndexWillBeCreatedIfItDoesNotExist(): void
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
    public function elasticIndexWillBeCreatedWithYAMLIndexConfigurationIfItDoesNotExist(): void
    {
        $index = $this->client->getIndex('functest');
        $this->assertFalse($index->exists());
        $expectedMapping = [
            'cacheEntry' => [
                'properties' => [
                    'text_field' => [
                        'type'     => 'string',
                        'analyzer' => 'customAnalyzer'
                    ]
                ]
            ]
        ];

        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations($this->cacheConfiguration);
        $cacheManager->getCache('functesting');

        $this->assertSame($expectedMapping, $index->getMapping());
    }

    /**
     * @test
     */
    public function setAndGetAddEntryToTheCacheAndRetrieveIt(): void
    {
        $cache = $this->createCache();

        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 0);
        $entry = $cache->get('my_test_identifier');

        $this->assertSame('mytestdata', $entry);
    }

    /**
     * @test
     */
    public function overwriteTypeNameOnIndexCreation(): void
    {
        $cacheConfiguration = [
            'functesting' => [
                'backend'  => 'TeamNeusta\\Elasticcache\\Cache\\Backend\\ElasticsearchBackend',
                'frontend' => 'TYPO3\\CMS\\Core\\Cache\\Frontend\\StringFrontend',
                'options'  => [
                    'indexName' => 'functest',
                    'typeName'  => 'customType'
                ],
            ]
        ];

        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations($cacheConfiguration);
        $cache = $cacheManager->getCache('functesting');

        $this->assertSame('customType', $cache->getBackend()->getType()->getName());
    }

    /**
     * @test
     */
    public function getEntryReturnsFalseIfNoEntryIsFound(): void
    {
        $cache = $this->createCache();
        $entry = $cache->get('not_found_identifier');

        $this->assertFalse($entry);
    }

    /**
     * @test
     */
    public function getEntryReturnsFalseIfLifetimeOfEntryIsLessThanNow(): void
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
     */
    public function hasReturnsFalseIfNoEntryIsFound(): void
    {
        $cache = $this->createCache();
        $entry = $cache->has('not_found_identifier');

        $this->assertFalse($entry);
    }

    /**
     * @test
     */
    public function hasReturnsFalseIfLifeTimeOfEntryIsLessThanNow(): void
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
     */
    public function removeDeletesEntryFromCache(): void
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        $cache->remove('my_test_identifier');

        $this->expectException(NotFoundException::class);

        $this->client->getIndex('functest')->getType('cacheEntry')->getDocument('my_test_identifier');
    }

    /**
     * @test
     */
    public function flushRemovesAllCacheEntries(): void
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
     */
    public function collectGarbageDeletesExpiredCacheEntries(): void
    {
        $cache = $this->createCache();
        $cache->set('my_test_identifier', 'mytestdata', ['test_tag'], 1);
        $cache->set('my_test_identifier1', 'mytestdata', ['test_tag'], 1500);
        $cache->set('my_test_identifier2', 'mytestdata', ['test_tag'], 1500);
        $cache->set('my_test_identifier3', 'mytestdata', ['test_tag'], 1);
        sleep(2);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(4, $count);

        $cache->collectGarbage();
        sleep(2);

        $count = $this->client->getIndex('functest')->getType('cacheEntry')->search(new MatchAll())->count();
        self::assertSame(2, $count);
    }

    /**
     * @test
     */
    public function flushByTagRemovesEntriesWithSpecifiedTag(): void
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
     */
    public function findIdentifiersByTagReturnsTaggedDocumentIdentifiers(): void
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
     * @return FrontendInterface
     * @throws NoSuchCacheException
     */
    protected function createCache(): FrontendInterface
    {
        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations($this->cacheConfiguration);

        return $cacheManager->getCache('functesting');
    }
}
