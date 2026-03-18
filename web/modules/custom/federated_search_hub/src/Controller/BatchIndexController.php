<?php

namespace Drupal\federated_search_hub\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\federated_search_hub\Service\FederatedIndexer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for federated search indexing endpoints.
 */
class BatchIndexController extends ControllerBase {

  /**
   * The federated indexer service.
   *
   * @var \Drupal\federated_search_hub\Service\FederatedIndexer
   */
  protected $indexer;

  /**
   * Constructs a BatchIndexController object.
   *
   * @param \Drupal\federated_search_hub\Service\FederatedIndexer $indexer
   *   The federated indexer service.
   */
  public function __construct(FederatedIndexer $indexer) {
    $this->indexer = $indexer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('federated_search_hub.indexer')
    );
  }

  /**
   * Custom access check using Pantheon Secret.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(Request $request) {
    $provided_key = $request->headers->get('X-Federated-Search-Key');

    if (empty($provided_key)) {
      return AccessResult::forbidden('Missing X-Federated-Search-Key header');
    }

    // Get the valid key from Pantheon Secrets
    if (function_exists('pantheon_get_secret')) {
      $valid_key = pantheon_get_secret('FEDERATED_SEARCH_API_KEY');
    }
    else {
      // Fallback for local development
      $valid_key = getenv('FEDERATED_SEARCH_API_KEY');
    }

    if (empty($valid_key)) {
      return AccessResult::forbidden('API key not configured');
    }

    // Use hash_equals to prevent timing attacks
    if (hash_equals($valid_key, $provided_key)) {
      return AccessResult::allowed()
        ->setCacheMaxAge(0);
    }

    return AccessResult::forbidden('Invalid API key');
  }

  /**
   * Batch index content from remote sites.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with indexing results.
   */
  public function batchIndex(Request $request) {
    try {
      // Get JSON payload
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        return new JsonResponse([
          'error' => 'Invalid JSON: ' . json_last_error_msg(),
          'status' => 'error',
        ], 400);
      }

      // Validate required fields
      if (empty($data['site_id'])) {
        return new JsonResponse([
          'error' => 'Missing required field: site_id',
          'status' => 'error',
        ], 400);
      }

      if (empty($data['items']) || !is_array($data['items'])) {
        return new JsonResponse([
          'error' => 'Missing or invalid items array',
          'status' => 'error',
        ], 400);
      }

      // Index the batch
      $result = $this->indexer->indexBatch($data);

      return new JsonResponse([
        'status' => 'success',
        'site_id' => $data['site_id'],
        'indexed' => $result['indexed'],
        'failed' => $result['failed'],
        'errors' => $result['errors'],
        'timestamp' => time(),
      ]);

    }
    catch (\Exception $e) {
      $this->getLogger('federated_search_hub')->error('Batch index error: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => $e->getMessage(),
        'status' => 'error',
      ], 500);
    }
  }

  /**
   * Get status of federated search index.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with index status.
   */
  public function status(Request $request) {
    try {
      $site_id = $request->query->get('site_id');
      $status = $this->indexer->getStatus($site_id);

      return new JsonResponse([
        'status' => 'success',
        'data' => $status,
      ]);

    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'status' => 'error',
      ], 500);
    }
  }

  /**
   * Delete all content for a specific site.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with deletion results.
   */
  public function deleteBySite(Request $request) {
    try {
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      if (empty($data['site_id'])) {
        return new JsonResponse([
          'error' => 'Missing required field: site_id',
          'status' => 'error',
        ], 400);
      }

      $deleted = $this->indexer->deleteBySiteId($data['site_id']);

      return new JsonResponse([
        'status' => 'success',
        'site_id' => $data['site_id'],
        'deleted' => $deleted,
        'timestamp' => time(),
      ]);

    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'status' => 'error',
      ], 500);
    }
  }

}
