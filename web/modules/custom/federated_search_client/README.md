# Federated Search Client

Client module for syncing content to a federated search hub on Pantheon.

## Overview

This module allows your Drupal site to automatically sync published content to a central federated search hub. Content is indexed at the hub and becomes searchable across multiple sites.

## Requirements

- Drupal 11
- Access to a Federated Search Hub site
- Pantheon Secrets Manager (for API key)
- HTTP client (Guzzle - included in Drupal core)

## Installation

1. **Enable the module:**
   ```bash
   drush en federated_search_client -y
   ```

2. **Configure Pantheon Secret:**

   The API key must match the one configured on the hub.

   In Pantheon Dashboard:
   - Go to your Organization → Secrets
   - The secret `FEDERATED_SEARCH_API_KEY` should already exist
   - If not, create it with the same value as the hub

3. **Configure the module:**

   Go to: `Configuration > Search > Federated Search Client`

   Or use the settings form:
   ```bash
   drush config:edit federated_search_client.settings
   ```

## Configuration

### Web UI

Navigate to: `/admin/config/search/federated-search-client`

Required settings:
- **Hub URL** - Base URL of your hub site
  - Example: `https://your-site-name.pantheonsite.io`
- **Site ID** - Unique identifier for this site
  - Use lowercase, numbers, hyphens only
  - Examples: `marketing`, `blog`, `site-docs`

Optional settings:
- **Site URL** - Leave blank to auto-detect
- **Content Types** - Select which types to sync (empty = all)
- **Batch Size** - Items per sync (default: 50)
- **Sync Interval** - How often to sync (default: hourly)

### Configuration File

Edit `config/sync/federated_search_client.settings.yml`:

```yaml
enabled: true
hub_url: 'https://your-site-name.pantheonsite.io'
site_id: 'marketing'
site_url: ''
content_types:
  - article
  - page
custom_fields: []
batch_size: 50
sync_interval: 3600
```

Then import:
```bash
drush config:import -y
```

## Usage

### Automatic Sync (Cron)

Once enabled, content syncs automatically on cron runs:

```bash
# Trigger cron manually
drush cron

# Or let Pantheon cron handle it (runs hourly by default)
```

Content is synced incrementally:
- Only changed content since last sync
- Respects batch size to avoid timeouts
- Failed items are logged for review

### Manual Sync

**Via Web UI:**
1. Go to `/admin/config/search/federated-search-client`
2. Click "Sync Now" button

**Via Drush:**

```bash
# Sync using configured batch size
drush federated-search:sync

# Sync specific number of items
drush federated-search:sync --limit=100

# Using alias
drush fs-sync
```

### Reset Sync State

To re-sync all content (not just changes):

```bash
# Reset last sync timestamp
drush federated-search:reset

# Then sync
drush federated-search:sync --limit=500
```

### Delete from Hub

To remove all your site's content from the hub:

```bash
drush federated-search:delete

# Or via alias
drush fs-delete
```

## Content Synced

### Default Fields

Every content item includes:
- **id** - Node ID
- **title** - Node title
- **body** - Full text content (HTML stripped)
- **summary** - Body summary if available
- **url** - Absolute URL to the content
- **created** - Creation timestamp
- **changed** - Last modified timestamp
- **author** - Author display name
- **language** - Content language
- **entity_type** - Always "node"
- **bundle** - Content type machine name

### Taxonomy Terms

All taxonomy term references are automatically included as tags:
- Categories
- Tags
- Any other taxonomy vocabularies

### Custom Fields

Add custom fields in the configuration:

```yaml
custom_fields:
  - field_subtitle
  - field_featured_text
  - field_department
```

Fields are automatically mapped with appropriate Solr prefixes based on type.

## How It Works

### Sync Process

1. **Cron Trigger** - Module hook runs on cron
2. **Query Content** - Finds changed content since last sync
3. **Prepare Batch** - Converts nodes to search-friendly format
4. **Send to Hub** - POSTs batch to hub API endpoint
5. **Hub Indexes** - Hub processes with Search API
6. **Update State** - Records last sync timestamp

### Incremental Updates

The module tracks the last sync timestamp and only syncs content changed after that point. This ensures:
- Efficient use of resources
- No duplicate indexing
- Only relevant updates sent to hub

### Error Handling

- Failed requests are logged to watchdog
- Partial batch failures are reported
- Sync continues on next cron run
- Use Drush for detailed error output

## API Integration

The client sends JSON payloads to the hub:

**Endpoint:** `POST {hub_url}/federated-search/batch-index`

**Headers:**
```
Content-Type: application/json
X-Federated-Search-Key: {secret}
```

**Payload:**
```json
{
  "site_id": "marketing",
  "site_url": "https://marketing.example.com",
  "items": [...]
}
```

## Drush Commands

### Available Commands

```bash
# Sync content to hub
drush federated-search:sync
drush fs-sync

# Sync with custom limit
drush fs-sync --limit=200

# Delete all content from hub
drush federated-search:delete
drush fs-delete

# Reset sync state
drush federated-search:reset
drush fs-reset
```

### Scheduled Sync via Terminus

For Pantheon sites, schedule regular syncs:

```bash
# Sync every hour on Live
terminus drush mysite.live -- fs-sync

# Set up Quicksilver hook (advanced)
# Add to pantheon.yml
```

## Monitoring

### Check Sync Status

**Web UI:**
- View last sync time in settings form
- Check watchdog logs for errors

**Drush:**
```bash
# View recent logs
drush watchdog:show --type=federated_search_client

# Check state
drush state:get federated_search_client.last_sync
```

### Hub Status

Query the hub to see your site's indexed content:

```bash
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://hub.example.com/federated-search/status?site_id=marketing"
```

## Troubleshooting

### Content Not Syncing

1. **Check if enabled:**
   ```bash
   drush config:get federated_search_client.settings enabled
   ```

2. **Verify cron is running:**
   ```bash
   drush cron
   ```

3. **Check for errors:**
   ```bash
   drush watchdog:show --type=federated_search_client
   ```

4. **Manually trigger sync:**
   ```bash
   drush fs-sync
   ```

### 403 Forbidden Errors

- Verify API key matches hub: `FEDERATED_SEARCH_API_KEY`
- Check Pantheon Secrets configuration
- Ensure secret is available to your environment

### Hub Not Receiving Content

1. **Test hub endpoint:**
   ```bash
   curl -X POST \
     -H "X-Federated-Search-Key: YOUR_KEY" \
     -H "Content-Type: application/json" \
     -d '{"site_id":"test","items":[]}' \
     https://hub.example.com/federated-search/batch-index
   ```

2. **Verify hub URL** in settings
3. **Check hub logs** for errors

### Performance Issues

- Reduce `batch_size` for slower sites
- Increase `sync_interval` to sync less frequently
- Use Drush instead of web UI for large batches
- Monitor PHP memory limits

## Best Practices

### Initial Sync

For a new site with existing content:

```bash
# 1. Configure settings first
drush config:set federated_search_client.settings enabled 1
drush config:set federated_search_client.settings hub_url "https://hub.example.com"
drush config:set federated_search_client.settings site_id "mysite"

# 2. Do initial large batch
drush fs-sync --limit=500

# 3. Run again until all content synced
drush fs-sync --limit=500

# 4. Enable automatic cron sync
```

### Content Updates

- Cron handles incremental updates automatically
- No action needed for normal edits/publishes
- Content is indexed on next cron run

### Site Migration

If changing site ID or hub:

```bash
# 1. Delete old content from hub
drush fs-delete

# 2. Update configuration
drush config:edit federated_search_client.settings

# 3. Reset sync state
drush fs-reset

# 4. Re-sync all content
drush fs-sync --limit=500
```

## Security

- API key never stored in code or database
- Retrieved from Pantheon Secrets at runtime
- All communication over HTTPS
- Hub validates every request

## Performance

- Minimal overhead on cron (only if enabled)
- Async HTTP requests don't block page loads
- Batch processing prevents timeouts
- Efficient incremental sync strategy

## Related Modules

- **Federated Search Hub** - Install on the central hub site

## Support

For issues or feature requests, consult the main project documentation.
