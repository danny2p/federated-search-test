<?php

namespace Drupal\federated_search_client\Commands;

use Drupal\federated_search_client\Service\BatchExporter;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Federated Search Client.
 */
class FederatedSearchCommands extends DrushCommands {

  /**
   * The batch exporter service.
   *
   * @var \Drupal\federated_search_client\Service\BatchExporter
   */
  protected $exporter;

  /**
   * Constructs a FederatedSearchCommands object.
   *
   * @param \Drupal\federated_search_client\Service\BatchExporter $exporter
   *   The batch exporter service.
   */
  public function __construct(BatchExporter $exporter) {
    parent::__construct();
    $this->exporter = $exporter;
  }

  /**
   * Sync content to federated search hub.
   *
   * @param array $options
   *   Command options.
   *
   * @command federated-search:sync
   * @option limit Maximum number of items to sync
   * @aliases fs-sync
   * @usage federated-search:sync
   *   Sync content using configured batch size
   * @usage federated-search:sync --limit=100
   *   Sync up to 100 items
   */
  public function sync(array $options = ['limit' => NULL]) {
    $limit = $options['limit'];

    try {
      $this->output()->writeln('Syncing content to federated search hub...');

      $result = $this->exporter->exportBatch($limit);

      $this->output()->writeln(sprintf(
        'Successfully synced %d items.',
        $result['exported']
      ));

      if (!empty($result['errors'])) {
        $this->output()->writeln('Errors:');
        foreach ($result['errors'] as $error) {
          $this->logger()->warning($error);
        }
      }

      // Update last sync time
      \Drupal::state()->set('federated_search_client.last_sync', time());

    }
    catch (\Exception $e) {
      $this->logger()->error('Sync failed: ' . $e->getMessage());
      return DrushCommands::EXIT_FAILURE;
    }

    return DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Delete all content for this site from the hub.
   *
   * @command federated-search:delete
   * @aliases fs-delete
   * @usage federated-search:delete
   *   Delete all content for this site from the federated search hub
   */
  public function delete() {
    if (!$this->io()->confirm('Are you sure you want to delete all content for this site from the federated search hub?')) {
      return DrushCommands::EXIT_SUCCESS;
    }

    try {
      $this->output()->writeln('Deleting content from federated search hub...');

      $result = $this->exporter->deleteAllFromHub();

      if ($result) {
        $this->output()->writeln('Successfully deleted all content from hub.');
      }
      else {
        $this->logger()->error('Failed to delete content from hub.');
        return DrushCommands::EXIT_FAILURE;
      }

    }
    catch (\Exception $e) {
      $this->logger()->error('Delete failed: ' . $e->getMessage());
      return DrushCommands::EXIT_FAILURE;
    }

    return DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Reset the last sync timestamp.
   *
   * @command federated-search:reset
   * @aliases fs-reset
   * @usage federated-search:reset
   *   Reset last sync timestamp to re-sync all content
   */
  public function reset() {
    \Drupal::state()->set('federated_search_client.last_sync', 0);
    $this->output()->writeln('Last sync timestamp reset. Next sync will process all content.');

    return DrushCommands::EXIT_SUCCESS;
  }

}
