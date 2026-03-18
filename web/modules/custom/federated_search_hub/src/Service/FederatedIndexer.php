<?php

namespace Drupal\federated_search_hub\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Task\IndexTaskManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for indexing federated content into Search API.
 */
class FederatedIndexer {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The index task manager.
   *
   * @var \Drupal\search_api\Task\IndexTaskManagerInterface
   */
  protected $indexTaskManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a FederatedIndexer object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\search_api\Task\IndexTaskManagerInterface $index_task_manager
   *   The index task manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    IndexTaskManagerInterface $index_task_manager,
    LoggerInterface $logger
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->indexTaskManager = $index_task_manager;
    $this->logger = $logger;
  }

  /**
   * Index a batch of content from a remote site.
   *
   * @param array $data
   *   The batch data containing:
   *   - site_id: Unique identifier for the remote site
   *   - site_url: Base URL of the remote site
   *   - items: Array of content items to index
   *
   * @return array
   *   Results containing indexed count, failed count, and errors.
   */
  public function indexBatch(array $data) {
    $result = [
      'indexed' => 0,
      'failed' => 0,
      'errors' => [],
    ];

    $site_id = $data['site_id'];
    $site_url = $data['site_url'] ?? '';

    // Get the Solr client directly
    try {
      $solr_client = $this->getSolrClient();

      // Create Solr documents
      $documents = [];
      foreach ($data['items'] as $item) {
        try {
          $doc = $this->createSolrDocument($item, $site_id, $site_url);
          if ($doc) {
            $documents[] = $doc;
          }
        }
        catch (\Exception $e) {
          $result['failed']++;
          $result['errors'][] = 'Item ' . ($item['id'] ?? 'unknown') . ': ' . $e->getMessage();
          $this->logger->error('Failed to create Solr document: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }

      // Index documents in Solr
      if (!empty($documents)) {
        $update = $solr_client->getUpdateQuery();

        // Add documents
        $update->addDocuments($documents);

        // Commit changes
        $update->addCommit();

        // Execute update
        $solr_client->update($update);

        $result['indexed'] = count($documents);
        $this->logger->info('Indexed @count documents from site @site_id', [
          '@count' => count($documents),
          '@site_id' => $site_id,
        ]);
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Batch index error: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }

    return $result;
  }

  /**
   * Create a Solr document from item data.
   *
   * @param array $item
   *   The item data.
   * @param string $site_id
   *   The site identifier.
   * @param string $site_url
   *   The site base URL.
   *
   * @return \Solarium\QueryType\Update\Query\Document|null
   *   The Solr document or NULL if creation failed.
   */
  protected function createSolrDocument(array $item, $site_id, $site_url) {
    // Validate required fields
    if (empty($item['id']) || empty($item['title'])) {
      throw new \Exception('Missing required fields: id or title');
    }

    $solr_client = $this->getSolrClient();
    $update = $solr_client->getUpdateQuery();
    $doc = $update->createDocument();

    // Generate unique Solr ID: site_id + entity_type + entity_id
    $entity_type = $item['entity_type'] ?? 'node';
    $solr_id = "federated:{$site_id}:{$entity_type}:{$item['id']}";
    $doc->setField('id', $solr_id);

    // Add federated search fields
    $doc->setField('ss_site_id', $site_id);
    $doc->setField('ss_site_url', $site_url);
    $doc->setField('ss_source_id', $item['id']);
    $doc->setField('ss_entity_type', $entity_type);

    // Add standard Search API fields
    $doc->setField('ss_search_api_id', 'federated_content/' . $solr_id);
    $doc->setField('ss_search_api_datasource', 'federated_content');
    $doc->setField('ss_search_api_language', $item['language'] ?? 'en');
    $doc->setField('index_id', 'federated_content');

    // Add content fields with Search API Solr field naming convention
    // tm_X3b_und_fieldname where X3b = URL encoded ';' and und = language
    $language = $item['language'] ?? 'und';
    $doc->setField('tm_X3b_' . $language . '_title', $item['title']);

    if (!empty($item['body'])) {
      $doc->setField('tm_X3b_' . $language . '_body', $item['body']);
    }

    if (!empty($item['summary'])) {
      $doc->setField('tm_X3b_' . $language . '_summary', $item['summary']);
    }

    // Add URL field - use the provided URL or construct one
    if (!empty($item['url'])) {
      $doc->setField('ss_url', $item['url']);
    }
    elseif (!empty($site_url)) {
      $doc->setField('ss_url', rtrim($site_url, '/') . '/node/' . $item['id']);
    }

    // Add bundle/type
    if (!empty($item['bundle'])) {
      $doc->setField('ss_type', $item['bundle']);
    }

    // Add creation/modification dates
    if (!empty($item['created'])) {
      $doc->setField('ds_created', $this->formatSolrDate($item['created']));
    }

    if (!empty($item['changed'])) {
      $doc->setField('ds_changed', $this->formatSolrDate($item['changed']));
    }

    // Add author if available
    if (!empty($item['author'])) {
      $doc->setField('ss_author', $item['author']);
    }

    // Add taxonomy terms if available
    if (!empty($item['tags']) && is_array($item['tags'])) {
      $doc->setField('tm_tags', $item['tags']);
    }

    // Add any custom fields
    if (!empty($item['custom_fields']) && is_array($item['custom_fields'])) {
      foreach ($item['custom_fields'] as $field_name => $field_value) {
        // Sanitize field name and add appropriate prefix
        $solr_field = $this->getSolrFieldName($field_name, $field_value);
        $doc->setField($solr_field, $field_value);
      }
    }

    // Add indexed timestamp
    $doc->setField('ds_federated_indexed', date('Y-m-d\TH:i:s\Z'));

    return $doc;
  }

  /**
   * Get Solr connector instance.
   *
   * @return \Drupal\search_api_solr\SolrConnectorInterface
   *   The Solr connector.
   *
   * @throws \Exception
   *   If Solr connection cannot be established.
   */
  protected function getSolrConnector() {
    // Use the existing Search API Solr server configuration
    $server_storage = $this->entityTypeManager->getStorage('search_api_server');
    $server = $server_storage->load('pantheon_search');

    if (!$server) {
      throw new \Exception('Pantheon Search server not found');
    }

    $backend = $server->getBackend();
    if (!method_exists($backend, 'getSolrConnector')) {
      throw new \Exception('Server backend does not support Solr');
    }

    return $backend->getSolrConnector();
  }

  /**
   * Get Solr client instance (wrapper for connector).
   *
   * @return object
   *   Object with createUpdate() and update() methods.
   */
  protected function getSolrClient() {
    return $this->getSolrConnector();
  }

  /**
   * Get status information about the federated search index.
   *
   * @param string|null $site_id
   *   Optional site ID to filter by.
   *
   * @return array
   *   Status information.
   */
  public function getStatus($site_id = NULL) {
    try {
      $solr_client = $this->getSolrClient();
      $query = $solr_client->getSelectQuery();

      if ($site_id) {
        $query->setQuery('ss_site_id:' . $site_id);
      }
      else {
        $query->setQuery('*:*');
      }

      $query->setRows(0);

      // Add facet to get counts per site
      $facetSet = $query->getFacetSet();
      $facetSet->createFacetField('site_counts')->setField('ss_site_id');

      $result = $solr_client->execute($query);

      $status = [
        'total_documents' => $result->getNumFound(),
        'sites' => [],
      ];

      // Get per-site counts
      $facet = $result->getFacetSet()->getFacet('site_counts');
      foreach ($facet as $site => $count) {
        $status['sites'][$site] = $count;
      }

      return $status;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get status: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Delete all documents for a specific site.
   *
   * @param string $site_id
   *   The site ID to delete.
   *
   * @return int
   *   Number of documents deleted.
   */
  public function deleteBySiteId($site_id) {
    try {
      // First, get count of documents to delete
      $status = $this->getStatus($site_id);
      $count = $status['sites'][$site_id] ?? 0;

      if ($count > 0) {
        $solr_client = $this->getSolrClient();
        $update = $solr_client->createUpdate();

        // Delete by query
        $update->addDeleteQuery('ss_site_id:' . $site_id);
        $update->addCommit();

        $solr_client->update($update);

        $this->logger->info('Deleted @count documents for site @site_id', [
          '@count' => $count,
          '@site_id' => $site_id,
        ]);
      }

      return $count;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete documents: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Format a timestamp for Solr.
   *
   * @param mixed $timestamp
   *   Unix timestamp or date string.
   *
   * @return string
   *   Solr-formatted date.
   */
  protected function formatSolrDate($timestamp) {
    if (is_numeric($timestamp)) {
      return date('Y-m-d\TH:i:s\Z', $timestamp);
    }
    return date('Y-m-d\TH:i:s\Z', strtotime($timestamp));
  }

  /**
   * Get Solr field name with appropriate prefix.
   *
   * @param string $field_name
   *   The field name.
   * @param mixed $value
   *   The field value.
   *
   * @return string
   *   The Solr field name with prefix.
   */
  protected function getSolrFieldName($field_name, $value) {
    // Determine prefix based on value type
    if (is_array($value)) {
      return 'tm_' . $field_name; // Multi-valued text
    }
    elseif (is_string($value)) {
      return 'ss_' . $field_name; // Single-valued string
    }
    elseif (is_int($value)) {
      return 'is_' . $field_name; // Integer
    }
    elseif (is_bool($value)) {
      return 'bs_' . $field_name; // Boolean
    }
    else {
      return 'ss_' . $field_name; // Default to string
    }
  }

}
