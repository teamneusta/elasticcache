<?php
declare(strict_types=1);

namespace TeamNeusta\Elasticcache\Cache\Backend;

use Elastica\Client;
use Elastica\Document;
use Elastica\Exception\NotFoundException;
use Elastica\Query\Match;
use Elastica\Query\MatchAll;
use Elastica\Query\Range;
use Elastica\Search;
use TYPO3\CMS\Core\Cache\Backend\AbstractBackend;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientBackendInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ElasticsearchBackend extends AbstractBackend implements TaggableBackendInterface, TransientBackendInterface
{

    /**
     * Elastic search host
     *
     * @var string
     */
    protected $hostname = 'localhost';

    /**
     * Document Type Name
     *
     * @var string
     */
    protected $typeName = 'cacheEntry';

    /**
     * Elastica Client
     *
     * @var \Elastica\Client
     */
    protected $elastica;

    /**
     * Elastica index name
     *
     * @var \Elastica\Index
     */
    protected $index;

    /**
     * @var \Elastica\Type
     */
    protected $type;

    /**
     * Elasticsearch port
     *
     * @var int
     */
    protected $port = 9200;

    /**
     * Elasticsearch path
     *
     * @var string
     */
    protected $path = '/';

    /**
     * Elasticsearch transport
     *
     * @var string
     */
    protected $transport = 'http';

    /**
     * Elasticsearch default index name
     *
     * @var string
     */
    protected $indexName = 't3cache';

    /**
     * Path to index configuration yaml
     * Example: EXT:myext/Configuration/Elastic/indexConfiguration.yaml
     *
     * @var string
     */
    protected $indexConfiguration = '';

    /**
     * @return \Elastica\Type
     */
    public function getType(): \Elastica\Type
    {
        return $this->type;
    }

    /**
     * @return \Elastica\Index
     */
    public function getIndex(): \Elastica\Index
    {
        return $this->index;
    }

    /**
     * @return string
     */
    public function getIndexConfiguration(): string
    {
        return $this->indexConfiguration;
    }

    /**
     * @param string $hostname
     */
    public function setHostname(string $hostname)
    {
        $this->hostname = $hostname;
    }

    /**
     * @param string $indexName
     */
    public function setIndexName(string $indexName)
    {
        $this->indexName = $indexName;
    }

    /**
     * @param string $indexConfiguration
     */
    public function setIndexConfiguration(string $indexConfiguration)
    {
        $this->indexConfiguration = $indexConfiguration;
    }

    /**
     * @param string $typeName
     */
    public function setTypeName(string $typeName)
    {
        $this->typeName = $typeName;
    }

    /**
     * @param int $port
     */
    public function setPort(int $port)
    {
        $this->port = $port;
    }

    /**
     * @param string $path
     */
    public function setPath(string $path)
    {
        $this->path = $path;
    }

    /**
     * @param string $transport
     */
    public function setTransport(string $transport)
    {
        $this->transport = $transport;
    }

    public function initializeObject()
    {
        $this->elastica = new Client(
            [
                'host'      => $this->hostname,
                'port'      => $this->port,
                'path'      => $this->path,
                'transport' => $this->transport,
            ]
        );
        $this->index = $this->elastica->getIndex($this->indexName);
        $this->type = $this->index->getType($this->typeName);
        if (!$this->index->exists()) {
            $this->createIndex();
            // add wait time until index was created before writing
            sleep(2);
        }
    }

    /**
     * Saves data in the cache.
     *
     * @param string $entryIdentifier An identifier for this specific cache entry
     * @param string $data            The data to be stored
     * @param array  $tags            Tags to associate with this cache entry. If the backend does not support tags, this option
     *                                can be ignored.
     * @param int    $lifetime        Lifetime of this cache entry in seconds. "0" means unlimited lifetime.
     *
     * @throws \TYPO3\CMS\Core\Cache\Exception if no cache frontend has been set.
     * @throws \TYPO3\CMS\Core\Cache\Exception\InvalidDataException if the data is not a string
     * @api
     */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null)
    {
        $lifetime = $lifetime ?? $this->defaultLifetime;
        if ($lifetime !== 0) {
            $lifetime += time();
        }
        $document = new Document(
            $entryIdentifier,
            [
                'content'   => $data,
                '_tags'     => $tags,
                '_lifetime' => $lifetime
            ],
            $this->typeName
        );
        $this->index->addDocuments([$document]);
    }

    /**
     * Loads data from the cache.
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     *
     * @return mixed The cache entry's content as a string or FALSE if the cache entry could not be loaded
     * @api
     */
    public function get($entryIdentifier)
    {
        try {
            return $this->getEntryWithLifeTimeCheck($entryIdentifier);
        } catch (NotFoundException $e) {
            return false;
        }
    }

    private function getEntryWithLifeTimeCheck(string $entryIdentifier)
    {
        $entry = $this->type->getDocument($entryIdentifier);
        $data = $entry->getData();
        if ($data['_lifetime'] === 0 || $data['_lifetime'] > time()) {
            return $data['content'];
        } else {
            return false;
        }
    }

    /**
     * Checks if a cache entry with the specified identifier exists.
     *
     * @param string $entryIdentifier An identifier specifying the cache entry
     *
     * @return bool TRUE if such an entry exists, FALSE if not
     * @api
     */
    public function has($entryIdentifier): bool
    {
        try {
            return (bool)$this->getEntryWithLifeTimeCheck($entryIdentifier);
        } catch (NotFoundException $e) {
            return false;
        }
    }

    /**
     * Removes all cache entries matching the specified identifier.
     * Usually this only affects one entry but if - for what reason ever -
     * old entries for the identifier still exist, they are removed as well.
     *
     * @param string $entryIdentifier Specifies the cache entry to remove
     *
     * @return bool TRUE if (at least) an entry could be removed or FALSE if no entry was found
     * @api
     */
    public function remove($entryIdentifier)
    {
        if ($this->has($entryIdentifier)) {
            $this->type->deleteById($entryIdentifier);

            return true;
        } else {
            return false;
        }
    }

    /**
     * Removes all cache entries of this cache.
     *
     * @api
     */
    public function flush()
    {
        $matchAll = new MatchAll();
        $this->type->deleteByQuery($matchAll);
    }

    /**
     * Does garbage collection
     * Removes expired entries from the cache
     *
     * @api
     */
    public function collectGarbage()
    {
        $range = new Range();
        $range->addField('_lifetime', [
            'from' => 1,
            'to'   => time()
        ]);
        $this->type->deleteByQuery($range);
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tag.
     *
     * @param string $tag The tag the entries must have
     *
     * @api
     */
    public function flushByTag($tag)
    {
        $query = new Match('_tags', $tag);
        $this->type->deleteByQuery($query);
    }

    /**
     * Finds and returns all cache entry identifiers which are tagged by the
     * specified tag
     * elasticsearch scan and scroll is used to make sure all matching entries are returned independent from the limit
     * of results per page
     *
     * @param string $tag The tag to search for
     *
     * @return array An array with identifiers of all matching entries. An empty array if no entries matched
     * @api
     */
    public function findIdentifiersByTag($tag): array
    {
        $query = new Match('_tags', $tag);
        $search = new Search($this->elastica);
        $search->search($query);
        $identifiers = [];
        foreach ($search->scanAndScroll() as $scrollId => $resultSet) {
            $documents = $resultSet->getDocuments();
            foreach ($documents as $document) {
                /** @var Document $document Document */
                $identifiers[] = $document->getId();
            }
        }

        return $identifiers;
    }

    /**
     * parses index configuration from yaml file into an array
     *
     * @return array
     */
    private function parseIndexConfiguration(): array
    {
        $configuration = [];
        $indexConfigurationFile = $this->getIndexConfiguration();
        if ($indexConfigurationFile !== '') {
            $fileLoader = GeneralUtility::makeInstance(YamlFileLoader::class);
            $configuration = $fileLoader->load($indexConfigurationFile);
        }

        return $configuration;
    }

    /**
     * creates new index with predefined configuration
     *
     * @return \Elastica\Response
     */
    private function createIndex(): \Elastica\Response
    {
        $indexConfiguration = $this->parseIndexConfiguration();

        return $this->index->create($indexConfiguration);
    }
}
