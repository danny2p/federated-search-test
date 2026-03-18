<?php

use Drupal\node\NodeInterface;

$indexer = \Drupal::service('federated_search_hub.indexer');
$entity_type_manager = \Drupal::service('entity_type.manager');
$site_id = 'danny-drupal-cms';
$site_url = \Drupal::request()->getSchemeAndHttpHost();

$node_storage = $entity_type_manager->getStorage('node');
$query = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('status', 1);

$nids = $query->execute();
echo 'Found ' . count($nids) . ' published nodes to index' . PHP_EOL;

$nodes = $node_storage->loadMultiple($nids);
$items = [];

foreach ($nodes as $node) {
  if (!$node instanceof NodeInterface) {
    continue;
  }

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

  if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
    $body = $node->get('body')->first();
    $item['body'] = strip_tags($body->value);

    if (!empty($body->summary)) {
      $item['summary'] = strip_tags($body->summary);
    }
  }

  $items[] = $item;

  // Debug: show first item
  if (count($items) == 1) {
    echo 'First item sample (node ' . $item['id'] . '):' . PHP_EOL;
    echo '  Title: ' . $item['title'] . PHP_EOL;
    echo '  Has body: ' . (isset($item['body']) ? 'YES (' . substr($item['body'], 0, 50) . '...)' : 'NO') . PHP_EOL;
    echo '  Language: ' . $item['language'] . PHP_EOL;
  }
}

echo 'Prepared ' . count($items) . ' items' . PHP_EOL;

$result = $indexer->indexBatch([
  'site_id' => $site_id,
  'site_url' => $site_url,
  'items' => $items,
]);

echo 'Successfully indexed: ' . $result['indexed'] . PHP_EOL;
echo 'Failed: ' . $result['failed'] . PHP_EOL;

if (!empty($result['errors'])) {
  echo 'Errors:' . PHP_EOL;
  foreach ($result['errors'] as $error) {
    echo '  - ' . $error . PHP_EOL;
  }
}
