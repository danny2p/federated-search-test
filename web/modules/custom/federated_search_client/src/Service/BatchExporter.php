<?php

namespace Drupal\federated_search_client\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for exporting content to federated search hub.
 */
class BatchExporter {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a BatchExporter object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerInterface $logger,
    StateInterface $state
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->state = $state;
  }

  /**
   * Export a batch of content to the federated search hub.
   *
   * @param int|null $limit
   *   Maximum number of items to export. NULL for config default.
   *
   * @return array
   *   Results array with exported count and errors.
   */
  public function exportBatch($limit = NULL) {
    $config = $this->configFactory->get('federated_search_client.settings');

    // Get configuration
    $hub_url = $config->get('hub_url');
    $site_id = $config->get('site_id');
    $batch_size = $limit ?? $config->get('batch_size') ?? 50;

    if (empty($hub_url) || empty($site_id)) {
      throw new \Exception('Federated search not configured. Please set hub_url and site_id.');
    }

    // Get content to export
    $items = $this->getContentToExport($batch_size);

    if (empty($items)) {
      return [
        'exported' => 0,
        'errors' => [],
      ];
    }

    // Prepare payload
    $payload = [
      'site_id' => $site_id,
      'site_url' => $config->get('site_url') ?? \Drupal::request()->getSchemeAndHttpHost(),
      'items' => $items,
    ];

    // Send to hub
    return $this->sendToHub($hub_url, $payload);
  }

  /**
   * Get content that needs to be exported.
   *
   * @param int $limit
   *   Maximum number of items to retrieve.
   *
   * @return array
   *   Array of content items ready for export.
   */
  protected function getContentToExport($limit) {
    $config = $this->configFactory->get('federated_search_client.settings');
    $content_types = $config->get('content_types') ?: [];

    $node_storage = $this->entityTypeManager->getStorage('node');

    // Build query
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1);

    // Filter by content types if configured
    if (!empty($content_types)) {
      $query->condition('type', $content_types, 'IN');
    }

    // Only get items changed since last sync
    $last_sync = $this->state->get('federated_search_client.last_sync', 0);
    if ($last_sync > 0) {
      $query->condition('changed', $last_sync, '>');
    }

    // Sort by changed date, oldest first
    $query->sort('changed', 'ASC');

    // Limit results
    $query->range(0, $limit);

    $nids = $query->execute();

    if (empty($nids)) {
      return [];
    }

    // Load nodes and convert to export format
    $nodes = $node_storage->loadMultiple($nids);
    $items = [];

    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $item = $this->prepareNodeForExport($node);
        if ($item) {
          $items[] = $item;
        }
      }
    }

    return $items;
  }

  /**
   * Prepare a node for export.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to prepare.
   *
   * @return array|null
   *   The prepared node data or NULL if node should not be exported.
   */
  protected function prepareNodeForExport(NodeInterface $node) {
    $config = $this->configFactory->get('federated_search_client.settings');

    // Build basic item structure
    $item = [
      'id' => $node->id(),
      'entity_type' => 'node',
      'bundle' => $node->bundle(),
      'title' => $node->getTitle(),
      'language' => $node->language()->getId(),
      'created' => $node->getCreatedTime(),
      'changed' => $node->getChangedTime(),
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];

    // Add author
    if ($node->getOwner()) {
      $item['author'] = $node->getOwner()->getDisplayName();
    }

    // Add body field - check for field_content first (new standard), then body (legacy)
    if ($node->hasField('field_content') && !$node->get('field_content')->isEmpty()) {
      $content = $node->get('field_content')->first();
      $item['body'] = strip_tags($content->value);
    }
    elseif ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->first();
      $item['body'] = strip_tags($body->value);

      if (!empty($body->summary)) {
        $item['summary'] = strip_tags($body->summary);
      }
    }

    // Add taxonomy terms (tags, categories, etc.)
    $tags = [];
    $field_definitions = $node->getFieldDefinitions();

    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->getType() === 'entity_reference' &&
          $field_definition->getSetting('target_type') === 'taxonomy_term') {

        if (!$node->get($field_name)->isEmpty()) {
          foreach ($node->get($field_name)->referencedEntities() as $term) {
            $tags[] = $term->getName();
          }
        }
      }
    }

    if (!empty($tags)) {
      $item['tags'] = array_unique($tags);
    }

    // Add custom fields if configured
    $custom_fields = $config->get('custom_fields') ?: [];
    if (!empty($custom_fields)) {
      $item['custom_fields'] = [];

      foreach ($custom_fields as $field_name) {
        if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
          $field_value = $node->get($field_name)->value;
          $item['custom_fields'][$field_name] = $field_value;
        }
      }
    }

    return $item;
  }

  /**
   * Send batch payload to the federated search hub.
   *
   * @param string $hub_url
   *   The hub base URL.
   * @param array $payload
   *   The payload to send.
   *
   * @return array
   *   Results array with exported count and errors.
   */
  protected function sendToHub($hub_url, array $payload) {
    try {
      // Get API key from Pantheon Secret
      if (function_exists('pantheon_get_secret')) {
        $api_key = pantheon_get_secret('FEDERATED_SEARCH_API_KEY');
      }
      else {
        $api_key = getenv('FEDERATED_SEARCH_API_KEY');
      }

      if (empty($api_key)) {
        throw new \Exception('FEDERATED_SEARCH_API_KEY not configured');
      }

      // Prepare request
      $endpoint = rtrim($hub_url, '/') . '/federated-search/batch-index';

      $response = $this->httpClient->request('POST', $endpoint, [
        'headers' => [
          'Content-Type' => 'application/json',
          'X-Federated-Search-Key' => $api_key,
        ],
        'json' => $payload,
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);

      if ($result['status'] === 'success') {
        return [
          'exported' => $result['indexed'] ?? count($payload['items']),
          'errors' => $result['errors'] ?? [],
        ];
      }
      else {
        throw new \Exception('Hub returned error: ' . ($result['error'] ?? 'Unknown error'));
      }

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to send batch to hub: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [
        'exported' => 0,
        'errors' => [$e->getMessage()],
      ];
    }
  }

  /**
   * Export a single node immediately.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to export.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function exportNode(NodeInterface $node) {
    $config = $this->configFactory->get('federated_search_client.settings');

    $hub_url = $config->get('hub_url');
    $site_id = $config->get('site_id');

    if (empty($hub_url) || empty($site_id)) {
      return FALSE;
    }

    $item = $this->prepareNodeForExport($node);
    if (!$item) {
      return FALSE;
    }

    $payload = [
      'site_id' => $site_id,
      'site_url' => $config->get('site_url') ?? \Drupal::request()->getSchemeAndHttpHost(),
      'items' => [$item],
    ];

    $result = $this->sendToHub($hub_url, $payload);

    return $result['exported'] > 0;
  }

  /**
   * Delete all content for this site from the hub.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function deleteAllFromHub() {
    $config = $this->configFactory->get('federated_search_client.settings');

    $hub_url = $config->get('hub_url');
    $site_id = $config->get('site_id');

    if (empty($hub_url) || empty($site_id)) {
      return FALSE;
    }

    try {
      if (function_exists('pantheon_get_secret')) {
        $api_key = pantheon_get_secret('FEDERATED_SEARCH_API_KEY');
      }
      else {
        $api_key = getenv('FEDERATED_SEARCH_API_KEY');
      }

      $endpoint = rtrim($hub_url, '/') . '/federated-search/delete';

      $response = $this->httpClient->request('POST', $endpoint, [
        'headers' => [
          'Content-Type' => 'application/json',
          'X-Federated-Search-Key' => $api_key,
        ],
        'json' => [
          'site_id' => $site_id,
        ],
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);

      return $result['status'] === 'success';

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete from hub: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
