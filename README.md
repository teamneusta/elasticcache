Elasticsearch based cache backend for TYPO3
===========================================

Provides a cache backend for TYPO3 which enables storing caches in elasticsearch. 

## Prerequisites
* Currently only tested with elasticsearch 2.x
* The "delete-by-query" plugin needs to be installed (see https://www.elastic.co/guide/en/elasticsearch/plugins/2.0/plugins-delete-by-query.html)

## Installation

`composer require teamneusta/elasticcache`

## Configuration

Add a section to your local configuration configuring the cache. You can either reconfigure
existing caches or add a new one for your own use. For more general information see the 
caching framework documentation (at https://docs.typo3.org/typo3cms/CoreApiReference/ApiOverview/CachingFramework/Index.html#caching)

The following options can be set:
* `host`: The elasticsearch host name (defaults to `localhost`)
* `port`: The elasticsearch port (defaults to `9200`)
* `path`: The elasticsearch path (defaults to `/`)
* `transport`: The elasticsearch transport protocol (defaults to `http`)
* `indexName`: The index name to use for this cache - make sure to choose a different one per cache
* `typeName`: The type name to use for this index
* `indexConfiguration`: The path to index configuration where you can set alternating mappings and analyzers
* `defaultLifeTime`: The default lifetime of a cache entry in this cache (in seconds - 0 means unlimited)

### Example

```    
'SYS' => [
	 'caching' => [
		 'cacheConfigurations' => [
			 'my_cache' => [
				 'backend' => 'TeamNeusta\\Elasticcache\\Cache\\Backend\\ElasticsearchBackend',
				 'frontend' => 'TYPO3\\CMS\\Core\\Cache\\Frontend\\VariableFrontend',
				 'options' => [
					 'defaultLifetime' => 0,
					 'indexName' => 'my_cache_index_name'
					 'typeName' => 'my_cache_type_name'
					 'indexConfiguration' => 'EXT:myext/Configuration/Elastic/indexConfiguration.yaml'
				 ],
			 ],
		 ],
	 ],
```

## Issues and Feedback

If you run into any issues, want to contribute or just give feedback, just use the github issue tracker.
