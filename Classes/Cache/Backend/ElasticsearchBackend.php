<?php
declare(strict_types = 1);

namespace TeamNeusta\Elasticcache\Cache\Backend;

use Elastica\Client;
use Elastica\Document;
use Elastica\Exception\NotFoundException;
use Elastica\Index;
use Elastica\Query\MatchAll;
use Elastica\Query\MatchQuery;
use Elastica\Query\Range;
use Elastica\Search;
use TYPO3\CMS\Core\Cache\Backend\AbstractBackend;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientBackendInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ElasticsearchBackend extends AbstractBackend implements TaggableBackendInterface, TransientBackendInterface
{
    protected string $hostname = 'localhost';
    protected Client $elastica;
    protected Index $index;
    protected int $port = 9200;
    protected string $path = '/';
    protected string $transport = 'http';
    protected string $indexName = 't3cache';
    /**
     * Path to index configuration yaml
     * Example: EXT:myext/Configuration/Elastic/indexConfiguration.yaml.
     */
    protected string $indexConfiguration = '';

    /**
     * @return Index
     */
    public function getIndex(): Index
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
    public function setHostname(string $hostname): void
    {
        $this->hostname = $hostname;
    }

    /**
     * @param string $indexName
     */
    public function setIndexName(string $indexName): void
    {
        $this->indexName = $indexName;
    }

    /**
     * @param string $indexConfiguration
     */
    public function setIndexConfiguration(string $indexConfiguration): void
    {
        $this->indexConfiguration = $indexConfiguration;
    }

    /**
     * @param int $port
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    /**
     * @param string $path
     */
    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    /**
     * @param string $transport
     */
    public function setTransport(string $transport): void
    {
        $this->transport = $transport;
    }

    public function initializeObject(): void
    {
        $this->elastica = new Client(
            [
                'host'      => $this->hostname,
                'port'      => $this->port,
                'path'      => $this->path,
                'transport' => $this->transport,
            ],
        );
        $this->index = $this->elastica->getIndex($this->indexName);
        if (!$this->index->exists()) {
            $this->createIndex();
            // add wait time until index was created before writing
            sleep(2);
        }
    }

    /**
     * Saves data in the cache.
     *
     * @param string   $entryIdentifier An identifier for this specific cache entry
     * @param string   $data            The data to be stored
     * @param string[] $tags            Tags to associate with this cache entry. If the backend does not support tags, this option
     *                                  can be ignored.
     * @param int      $lifetime        Lifetime of this cache entry in seconds. "0" means unlimited lifetime.
     */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null): void
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
                '_lifetime' => $lifetime,
            ],
            $this->index ?? '',
        );
        $this->index->addDocuments([$document]);
    }

    /**
     * Loads data from the cache.
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     *
     * @return mixed The cache entry's content as a string or FALSE if the cache entry could not be loaded
     */
    public function get($entryIdentifier)
    {
        try {
            return $this->getEntryWithLifeTimeCheck($entryIdentifier);
        } catch (NotFoundException $e) {
            return false;
        }
    }

    /**
     * Checks if a cache entry with the specified identifier exists.
     *
     * @param string $entryIdentifier An identifier specifying the cache entry
     *
     * @return bool TRUE if such an entry exists, FALSE if not
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
     */
    public function remove($entryIdentifier): bool
    {
        if (!$this->has($entryIdentifier)) {
            return false;
        }

        $this->index->deleteById($entryIdentifier);

        return true;
    }

    /**
     * Removes all cache entries of this cache.
     */
    public function flush(): void
    {
        $matchAll = new MatchAll();
        $this->index->deleteByQuery($matchAll);
    }

    /**
     * Does garbage collection
     * Removes expired entries from the cache.
     */
    public function collectGarbage(): void
    {
        $range = new Range();
        $range->addField('_lifetime', [
            'from' => 1,
            'to'   => time(),
        ]);
        $this->index->deleteByQuery($range);
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tag.
     *
     * @param string $tag The tag the entries must have
     */
    public function flushByTag($tag): void
    {
        $query = new MatchQuery('_tags', $tag);
        $this->index->deleteByQuery($query);
    }

    /**
     * Finds and returns all cache entry identifiers which are tagged by the
     * specified tag
     * elasticsearch scan and scroll is used to make sure all matching entries are returned independent from the limit
     * of results per page.
     *
     * @param string $tag The tag to search for
     *
     * @return string[] An array with identifiers of all matching entries. An empty array if no entries matched
     *
     * @api
     */
    public function findIdentifiersByTag($tag): array
    {
        $query = new MatchQuery('_tags', $tag);
        $search = new Search($this->elastica);
        $search->search($query);
        $identifiers = [];
        foreach ($search->scroll() as $resultSet) {
            $documents = $resultSet->getDocuments();
            foreach ($documents as $document) {
                $documentId = $document->getId();
                if (empty($documentId)) {
                    continue;
                }

                $identifiers[] = $documentId;
            }
        }

        return $identifiers;
    }

    /**
     * @param string $entryIdentifier
     *
     * @return false|mixed
     */
    private function getEntryWithLifeTimeCheck(string $entryIdentifier)
    {
        $entry = $this->index->getDocument($entryIdentifier);
        $data = $entry->getData();
        $content = false;
        if (is_array($data) && ($data['_lifetime'] === 0 || $data['_lifetime'] > time())) {
            $content = $data['content'];
        }

        return $content;
    }

    /**
     * parses index configuration from yaml file into an array.
     *
     * @return array<mixed>
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
     * creates new index with predefined configuration.
     */
    private function createIndex(): void
    {
        $indexConfiguration = $this->parseIndexConfiguration();

        $this->index->create($indexConfiguration);
    }
}
