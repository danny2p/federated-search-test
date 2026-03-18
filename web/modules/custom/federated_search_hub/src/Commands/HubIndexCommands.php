<?php

namespace Drupal\federated_search_hub\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\federated_search_hub\Service\FederatedIndexer;
use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for indexing hub site content.
 */
class HubIndexCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The federated indexer service.
   *
   * @var \Drupal\federated_search_hub\Service\FederatedIndexer
   */
  protected $indexer;

  /**
   * HubIndexCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\federated_search_hub\Service\FederatedIndexer $indexer
   *   The federated indexer service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, FederatedIndexer $indexer) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->indexer = $indexer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('federated_search_hub.indexer')
    );
  }

  /**
   * Index hub site content into federated search.
   *
   * @param string $siteId
   *   The site ID to use for hub content (default: hub site URL).
   *
   * @command federated-search-hub:index-hub
   * @aliases fsh:index-hub
   * @usage federated-search-hub:index-hub
   *   Index all hub site content into federated search.
   * @usage federated-search-hub:index-hub your-site-name
   *   Index hub content with specific site ID.
   */
  public function indexHub($siteId = NULL) {
    // Get site ID from parameter or use default
    if (empty($siteId)) {
      $siteId = \Drupal::request()->getSchemeAndHttpHost();
      $siteId = parse_url($siteId, PHP_URL_HOST);
      $siteId = str_replace('.', '-', $siteId); // Convert to site ID format
    }

    $siteUrl = \Drupal::request()->getSchemeAndHttpHost();

    $this->output()->writeln("Indexing hub site content with site_id: $siteId");

    // Get all published nodes
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1);

    $nids = $query->execute();

    if (empty($nids)) {
      $this->output()->writeln('No published nodes found.');
      return;
    }

    $nodes = $node_storage->loadMultiple($nids);
    $items = [];

    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $item = $this->prepareNodeForIndex($node);
        if ($item) {
          $items[] = $item;
        }
      }
    }

    if (empty($items)) {
      $this->output()->writeln('No items to index.');
      return;
    }

    // Index in batches
    $batch_size = 50;
    $batches = array_chunk($items, $batch_size);
    $total_indexed = 0;

    foreach ($batches as $batch) {
      $result = $this->indexer->indexBatch([
        'site_id' => $siteId,
        'site_url' => $siteUrl,
        'items' => $batch,
      ]);

      $total_indexed += $result['indexed'];

      if (!empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
          $this->output()->writeln('Error: ' . $error);
        }
      }
    }

    $this->output()->writeln("Successfully indexed $total_indexed items from hub site.");
  }

  /**
   * Prepare a node for indexing.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to prepare.
   *
   * @return array|null
   *   The prepared node data or NULL.
   */
  protected function prepareNodeForIndex(NodeInterface $node) {
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

    // Add body field
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->first();
      $item['body'] = strip_tags($body->value);

      if (!empty($body->summary)) {
        $item['summary'] = strip_tags($body->summary);
      }
    }

    return $item;
  }

}
