# Federated Search Setup Guide

Complete guide to setting up federated search across multiple Pantheon Drupal sites.

## Overview

This guide walks you through setting up a central search hub that indexes content from multiple Drupal sites, all hosted on Pantheon.

**Architecture:**
- **Hub Site** (danny-drupal-cms) - Central search server
- **Client Sites** (4-10 sites) - Push content to hub via API
- **Authentication** - Pantheon Secrets Manager
- **Search Engine** - Pantheon Solr 8

## Prerequisites

- Multiple Drupal 11 sites on Pantheon
- All sites in the same Pantheon organization (for shared secrets)
- Solr 8 enabled on hub site
- Drush access to all sites

## Phase 1: Hub Setup (danny-drupal-cms)

### Step 1: Enable Solr

Ensure `pantheon.yml` contains:

```yaml
api_version: 1
search:
  version: 8
```

Commit and push if changed:
```bash
git add pantheon.yml
git commit -m "Enable Solr 8"
git push origin master
```

### Step 2: Install Hub Module

```bash
# Enable the module
terminus drush danny-drupal-cms.dev -- en federated_search_hub -y

# Clear cache
terminus drush danny-drupal-cms.dev -- cr

# Verify Search API is configured
terminus drush danny-drupal-cms.dev -- search-api:status
```

### Step 3: Configure Pantheon Secret

**In Pantheon Dashboard:**

1. Go to your **Organization** (not individual site)
2. Click **Secrets** tab
3. Click **Create New Secret**
4. Enter:
   - **Name:** `FEDERATED_SEARCH_API_KEY`
   - **Value:** Generate secure key (see below)
   - **Scope:** Organization

**Generate Secure Key:**

```bash
# Option 1: OpenSSL
openssl rand -base64 64

# Option 2: PHP
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# Option 3: Online tool (be careful)
# Use a password generator with 64+ characters
```

Copy the generated key and save it as the secret value.

### Step 4: Verify Hub Installation

Test the endpoints:

```bash
# Get your API key (on Pantheon)
# It's stored in the secret, so we'll use it in the next command

# Test status endpoint
curl -H "X-Federated-Search-Key: YOUR_KEY_HERE" \
  "https://dev-danny-drupal-cms.pantheonsite.io/federated-search/status"

# Expected response:
# {"status":"success","data":{"total_documents":0,"sites":{}}}

# Test search proxy
curl -H "X-Federated-Search-Key: YOUR_KEY_HERE" \
  "https://dev-danny-drupal-cms.pantheonsite.io/solr-proxy.php?q=*:*"
```

## Phase 2: Client Site Setup

Repeat these steps for each remote site that will push content to the hub.

### Step 1: Install Client Module

**Copy the module to your client site:**

```bash
# From danny-drupal-cms, copy the client module
cd /path/to/client-site
rsync -av /path/to/danny-drupal-cms/web/modules/custom/federated_search_client/ \
  web/modules/custom/federated_search_client/

# Or clone if in version control
# git clone ...
```

**Enable the module:**

```bash
terminus drush client-site.dev -- en federated_search_client -y
terminus drush client-site.dev -- cr
```

### Step 2: Configure Client

**Option A: Via Web UI**

1. Go to `/admin/config/search/federated-search-client`
2. Configure:
   - **Hub URL:** `https://dev-danny-drupal-cms.pantheonsite.io`
   - **Site ID:** Unique ID (e.g., `marketing`, `blog`, `docs`)
   - **Content Types:** Select which types to sync
   - **Enable automatic sync:** Check the box
3. Click **Save**

**Option B: Via Drush**

```bash
# Configure settings
terminus drush client-site.dev -- config:set federated_search_client.settings enabled 1
terminus drush client-site.dev -- config:set federated_search_client.settings hub_url "https://dev-danny-drupal-cms.pantheonsite.io"
terminus drush client-site.dev -- config:set federated_search_client.settings site_id "marketing"

# For specific content types (YAML format)
terminus drush client-site.dev -- config:set federated_search_client.settings content_types "['article','page']"
```

### Step 3: Initial Sync

Do an initial sync to send existing content:

```bash
# Sync first batch (50 items default)
terminus drush client-site.dev -- fs-sync

# Continue syncing until all content sent
terminus drush client-site.dev -- fs-sync --limit=200

# Check how many items were sent
terminus drush client-site.dev -- state:get federated_search_client.last_sync
```

**Monitor progress:**

```bash
# On hub, check status
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://dev-danny-drupal-cms.pantheonsite.io/federated-search/status?site_id=marketing"

# Should show:
# {"status":"success","data":{"total_documents":50,"sites":{"marketing":50}}}
```

### Step 4: Verify Automatic Sync

**Enable cron on client site:**

```bash
# Run cron manually
terminus drush client-site.dev -- cron

# Check logs
terminus drush client-site.dev -- watchdog:show --type=federated_search_client
```

**Pantheon cron runs hourly by default**, so content will automatically sync.

## Phase 3: Deploy to Production

### Step 1: Deploy Hub to Live

```bash
# Commit custom modules
cd /path/to/danny-drupal-cms
git add web/modules/custom/federated_search_hub web/solr-proxy.php
git commit -m "Add federated search hub"
git push origin master

# Deploy to Test
terminus env:deploy danny-drupal-cms.test --sync-content --updatedb

# Enable module on Test
terminus drush danny-drupal-cms.test -- en federated_search_hub -y

# Deploy to Live
terminus env:deploy danny-drupal-cms.live --updatedb

# Enable module on Live
terminus drush danny-drupal-cms.live -- en federated_search_hub -y
```

### Step 2: Update Client Sites to Point to Live

**Update hub_url to Live:**

```bash
# For each client site
terminus drush client-site.live -- config:set federated_search_client.settings hub_url "https://danny-drupal-cms.pantheonsite.io"

# Re-sync to Live hub
terminus drush client-site.live -- fs-reset
terminus drush client-site.live -- fs-sync --limit=500
```

### Step 3: Verify Production

```bash
# Check Live hub status
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://danny-drupal-cms.pantheonsite.io/federated-search/status"

# Should show all sites:
# {
#   "status": "success",
#   "data": {
#     "total_documents": 1500,
#     "sites": {
#       "marketing": 500,
#       "blog": 600,
#       "docs": 400
#     }
#   }
# }
```

## Phase 4: Search Interface

### Option A: Centralized Search (Simplest)

Add a search page on the hub site using Views + Search API.

1. Go to `/admin/structure/views`
2. Create new view of "Index Primary"
3. Add search filter
4. Configure display
5. Remote sites link to: `https://hub.example.com/search?keys=query`

### Option B: Distributed Search (Better UX)

Each site has its own search form that queries the hub.

**Create search form on client sites:**

```php
// In your theme or custom module
function mysite_form_search_alter(&$form, $form_state, $form_id) {
  $form['#action'] = 'https://danny-drupal-cms.pantheonsite.io/search';
  $form['#method'] = 'get';
}
```

**Or use JavaScript widget** (see `FEDERATED_SEARCH_WIDGET.html` for example)

### Option C: API Integration

Query the hub via API for full control:

```javascript
// Example: Search from any site via JavaScript
fetch('https://hub.example.com/solr-proxy.php?q=drupal&site=marketing', {
  headers: {
    'X-Federated-Search-Key': 'YOUR_KEY'
  }
})
.then(res => res.json())
.then(data => {
  // Display results
  console.log(data.response.docs);
});
```

## Monitoring & Maintenance

### Check Index Health

```bash
# Hub Solr status
terminus drush danny-drupal-cms.live -- search-api:status

# Check document counts per site
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://danny-drupal-cms.pantheonsite.io/federated-search/status"
```

### Review Sync Logs

```bash
# Client site logs
terminus drush client-site.live -- watchdog:show --type=federated_search_client --count=50

# Hub logs
terminus drush danny-drupal-cms.live -- watchdog:show --type=federated_search_hub --count=50
```

### Manual Re-index

If you need to rebuild the entire index:

```bash
# 1. Clear hub index
terminus drush danny-drupal-cms.live -- search-api:clear

# 2. On each client site, reset and re-sync
terminus drush client-site.live -- fs-reset
terminus drush client-site.live -- fs-sync --limit=500

# Repeat fs-sync until all content synced
```

### Rotate API Key

To rotate the API key:

1. Generate new key
2. Update Pantheon Secret in Organization
3. Test one client site
4. Wait for change to propagate (can take a few minutes)
5. Verify all sites can still sync

## Troubleshooting

### Problem: Client site getting 403 errors

**Solution:**
```bash
# Verify secret is accessible
terminus drush client-site.live -- php-eval "var_dump(pantheon_get_secret('FEDERATED_SEARCH_API_KEY'));"

# Should output the key, not null
```

### Problem: Content not appearing in search

**Solution:**
```bash
# 1. Verify content was sent
terminus drush client-site.live -- state:get federated_search_client.last_sync

# 2. Check hub received it
curl -H "X-Federated-Search-Key: YOUR_KEY" \
  "https://hub.example.com/federated-search/status?site_id=client"

# 3. Reindex if needed
terminus drush danny-drupal-cms.live -- search-api:index
```

### Problem: Search is slow

**Solutions:**
- Use `/solr-proxy.php` instead of Drupal search
- Add CDN caching for search results
- Reduce number of results (`rows` parameter)
- Add more specific filters (`fq` parameter)

## Advanced Configuration

### Custom Field Mapping

To index custom fields from client sites:

```bash
# On client site
terminus drush client-site.live -- config:set federated_search_client.settings custom_fields "['field_subtitle','field_department']"
```

### Exclude Content from Sync

```php
// In custom module on client site
use Drupal\node\NodeInterface;

/**
 * Implements hook_federated_search_item_prepare_alter().
 */
function mymodule_federated_search_item_prepare_alter(&$item, NodeInterface $node) {
  // Don't sync draft content
  if ($node->isPublished() === FALSE) {
    $item = NULL; // Exclude from sync
  }

  // Add custom data
  if ($item && $node->hasField('field_priority')) {
    $item['custom_fields']['priority'] = $node->field_priority->value;
  }
}
```

### Scheduled Full Re-sync

Set up quarterly full re-sync via cron:

```bash
# Add to Quicksilver or external cron
0 0 1 */3 * terminus drush client-site.live -- fs-reset && terminus drush client-site.live -- fs-sync --limit=1000
```

## Performance Benchmarks

Expected performance with proper configuration:

- **Search queries:** < 100ms (via solr-proxy.php)
- **Batch indexing:** 50-100 items/second
- **Index size:** Up to 100K documents on Pantheon Solr 8
- **Sites supported:** 10+ active client sites

## Next Steps

1. ✅ Set up hub with federated_search_hub module
2. ✅ Configure Pantheon Secret for API key
3. ✅ Install federated_search_client on first client site
4. ✅ Test sync and search functionality
5. ⬜ Roll out to additional client sites
6. ⬜ Build search interface (Views or custom)
7. ⬜ Monitor and optimize

## Support Resources

- **Pantheon Solr Docs:** https://pantheon.io/docs/solr
- **Pantheon Secrets:** https://pantheon.io/docs/secrets
- **Search API:** https://www.drupal.org/project/search_api
- **Module READMEs:** See individual module folders

## Frequently Asked Questions

**Q: Can I use this with non-Pantheon sites?**
A: The modules rely on Pantheon Secrets Manager and environment variables. You'd need to modify the authentication mechanism for other hosts.

**Q: What if I have more than 10 sites?**
A: The architecture supports more, but monitor Solr index size and query performance. Consider using multiple hubs for 50+ sites.

**Q: Can remote sites search directly?**
A: Yes! Use the solr-proxy.php endpoint with the API key, or build a search interface that queries the hub.

**Q: How do I handle multilingual content?**
A: The modules preserve language codes. Configure Search API language processors on the hub for language-specific handling.

**Q: Can I index media/files?**
A: The current implementation indexes nodes. You can extend the BatchExporter to support media entities.

**Q: What happens if the hub goes down?**
A: Client sites continue working normally. Sync operations will fail but will retry on next cron run.
