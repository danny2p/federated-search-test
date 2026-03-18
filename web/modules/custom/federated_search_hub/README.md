# Federated Search Hub

Central hub module for federated search across multiple Pantheon Drupal sites.

## Overview

This module turns your Drupal site into a central search hub that can index and search content from multiple remote Drupal sites. Remote sites use the **Federated Search Client** module to push their content to this hub's Solr index.

## Architecture

The hub provides two search mechanisms:

1. **Lightweight PHP Proxy** (`/solr-proxy.php`) - For fast search queries without Drupal bootstrap
2. **Drupal API Endpoints** - For content indexing with full Search API processing

## Requirements

- Drupal 11
- Search API module
- Search API Solr module
- Search API Pantheon module
- Pantheon hosting with Solr 8 enabled
- Pantheon Secrets Manager (for API key)

## Installation

1. **Enable the module:**
   ```bash
   drush en federated_search_hub -y
   ```

2. **Configure Pantheon Secret:**

   In Pantheon Dashboard:
   - Go to your Organization → Secrets
   - Create a new secret:
     - Name: `FEDERATED_SEARCH_API_KEY`
     - Value: Generate a secure random string (64+ characters)
     - Scope: Organization (all sites will use the same key)

   Generate a secure key:
   ```bash
   # On your local machine or in the Pantheon dashboard
   openssl rand -base64 64
   ```

3. **Verify Solr Configuration:**

   Ensure your `pantheon.yml` includes:
   ```yaml
   search:
     version: 8
   ```

4. **Configure Search API:**

   The module works with the default Search API Pantheon configuration. Ensure:
   - Server: `pantheon_search` is enabled
   - Index: `primary` is enabled and indexing content

## API Endpoints

### POST /federated-search/batch-index

Index a batch of content from a remote site.

**Headers:**
- `Content-Type: application/json`
- `X-Federated-Search-Key: {your-secret-key}`

**Request Body:**
```json
{
  "site_id": "marketing",
  "site_url": "https://marketing.example.com",
  "items": [
    {
      "id": "123",
      "entity_type": "node",
      "bundle": "article",
      "title": "Example Article",
      "body": "Full article content...",
      "summary": "Short summary...",
      "url": "https://marketing.example.com/article/example",
      "created": 1234567890,
      "changed": 1234567890,
      "author": "John Doe",
      "language": "en",
      "tags": ["drupal", "pantheon"]
    }
  ]
}
```

**Response:**
```json
{
  "status": "success",
  "site_id": "marketing",
  "indexed": 1,
  "failed": 0,
  "errors": [],
  "timestamp": 1234567890
}
```

### GET /federated-search/status

Get index status and document counts.

**Query Parameters:**
- `site_id` (optional) - Filter by specific site

**Response:**
```json
{
  "status": "success",
  "data": {
    "total_documents": 1500,
    "sites": {
      "marketing": 500,
      "blog": 600,
      "docs": 400
    }
  }
}
```

### POST /federated-search/delete

Delete all content for a specific site.

**Request Body:**
```json
{
  "site_id": "marketing"
}
```

## Search Proxy

The lightweight search proxy at `/solr-proxy.php` provides fast search without Drupal bootstrap.

### Usage

**Search all sites:**
```bash
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://hub.example.com/solr-proxy.php?q=drupal&rows=10"
```

**Search specific site:**
```bash
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://hub.example.com/solr-proxy.php?q=pantheon&site=marketing&rows=20"
```

**With highlighting:**
```bash
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://hub.example.com/solr-proxy.php?q=search&hl=true"
```

### Query Parameters

- `q` - Search query (default: `*:*`)
- `site` - Filter by site ID
- `start` - Result offset for pagination (default: 0)
- `rows` - Number of results (default: 10, max: 100)
- `sort` - Sort order (e.g., `score desc`, `ds_created desc`)
- `fq` - Additional filter queries
- `hl` - Enable highlighting (`true`/`false`)

## Solr Schema Fields

The hub adds these custom fields to track federated content:

- `ss_site_id` - Source site identifier
- `ss_site_url` - Source site base URL
- `ss_source_id` - Original entity ID on source site
- `ss_entity_type` - Entity type (usually "node")
- `ss_url` - Full URL to original content
- `ds_federated_indexed` - Timestamp when indexed

## Security

- All API endpoints require the `X-Federated-Search-Key` header
- API key is stored in Pantheon Secrets Manager (not in code)
- Uses `hash_equals()` to prevent timing attacks
- HTTPS required for all communication

## Performance

The search proxy provides:
- **No Drupal bootstrap** - Direct Solr queries
- **Sub-100ms response times** for most queries
- **Pantheon Global CDN** compatible
- **Scalable** to 100K+ documents across 10+ sites

## Monitoring

Check index health:

```bash
# Via API
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://hub.example.com/federated-search/status"

# Via Drupal UI
drush @hub.live search-api:status
```

## Troubleshooting

**403 Forbidden errors:**
- Verify API key is correctly configured in Pantheon Secrets
- Check that remote sites are using the same secret name
- Ensure header is `X-Federated-Search-Key` (case-sensitive)

**No results in search:**
- Verify content was successfully indexed (check status endpoint)
- Check Solr index: `drush @hub.live search-api:status`
- Review logs: `drush @hub.live watchdog:show --type=federated_search_hub`

**Slow search performance:**
- Use the lightweight proxy (`/solr-proxy.php`) instead of Drupal endpoints
- Optimize Solr queries (reduce `rows`, add specific filters)
- Enable CDN caching for proxy responses

## Related Modules

- **Federated Search Client** - Install on remote sites to push content to this hub

## Support

For issues or questions, see the main project documentation.
