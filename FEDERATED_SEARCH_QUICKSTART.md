# Federated Search Quick Start

Fast-track setup guide for federated search across Pantheon Drupal sites.

## 🎯 What You're Building

A central search hub (your-site-name) that indexes content from multiple Drupal sites.

**Result:** Search once, find content from all your sites.

## ⚡ Quick Setup (30 minutes)

### 1️⃣ Hub Setup (5 min)

```bash
# On your-site-name
cd /path/to/your-site-name

# enable the module (and requisite Pantheon solr setup)
# see https://docs.pantheon.io/guides/pantheon-search/solr-drupal/solr-drupal)
terminus drush your-site-name.dev -- en federated_search_hub -y
terminus drush your-site-name.dev -- cr
```

### 2️⃣ Create API Key (5 min)

**Pantheon Dashboard:**
1. Install Terminus and Secrets Manager Plugin: https://github.com/pantheon-systems/terminus-secrets-manager-plugin
2. Create a new Organization Secret: terminus secret:org:set {your-org-id} FEDERATED_SEARCH_API_KEY {key} --scope=web, --type=runtime
3. For your Key: Generate the key with: `openssl rand -base64 64`


**Copy the key** - you'll test with it next.

### 3️⃣ Test Hub (2 min)

```bash
# Replace YOUR_KEY with the secret you created
export API_KEY="your-api-key-here"

# Test status endpoint
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://dev-your-site-name.pantheonsite.io/federated-search/status"

# Expected: {"status":"success","data":{"total_documents":0,"sites":{}}}

# Test search proxy
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://dev-your-site-name.pantheonsite.io/solr-proxy.php?q=*:*"

# Expected: Solr JSON response
```

✅ Hub is ready!

### 4️⃣ First Client Site (10 min)

**Copy module to client site:**

```bash
# From your-site-name
cd web/modules/custom/federated_search_client

# Copy to client site however you want (replace path)
rsync -av . /path/to/client-site/web/modules/custom/federated_search_client/

# Or commit to git and pull on client site
```

**Enable and configure:**

```bash
# Enable module
terminus drush client-site.dev -- en federated_search_client -y

# Configure
terminus drush client-site.dev -- config:set federated_search_client.settings enabled 1
terminus drush client-site.dev -- config:set federated_search_client.settings hub_url "https://dev-your-site-name.pantheonsite.io"
terminus drush client-site.dev -- config:set federated_search_client.settings site_id "site-one"

# Initial sync
terminus drush client-site.dev -- fs-sync
```

### 5️⃣ Verify It Works (3 min)

```bash
# Check hub received content
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://dev-your-site-name.pantheonsite.io/federated-search/status"

# Should show:
# {"status":"success","data":{"total_documents":50,"sites":{"site-one":50}}}

# Search for content
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://dev-your-site-name.pantheonsite.io/solr-proxy.php?q=drupal&site=site-one"
```

✅ **Success!** Your first client is syncing.

### 6️⃣ Add More Sites (5 min each)

Repeat step 4 for each additional site:
- Copy the module
- Enable it
- Configure with unique `site_id`
- Run initial sync

## 📋 Configuration Checklist

### Hub (your-site-name)
- [x] Module files created
- [ ] Module enabled (`federated_search_hub`)
- [ ] Pantheon Secret created (`FEDERATED_SEARCH_API_KEY`)
- [ ] Solr 8 enabled in pantheon.yml
- [ ] Status endpoint tested
- [ ] Search proxy tested

### Each Client Site
- [ ] Module copied to site
- [ ] Module enabled (`federated_search_client`)
- [ ] Hub URL configured
- [ ] Unique site_id set
- [ ] Initial sync completed
- [ ] Automatic sync enabled
- [ ] Verify content in hub

## 🔧 Common Commands

### Hub Commands

```bash
# Check index status
terminus drush hub.dev -- search-api:status

# View logs
terminus drush hub.dev -- watchdog:show --type=federated_search_hub

# Check document counts
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub-url/federated-search/status"
```

### Client Commands

```bash
# Sync content now
terminus drush client.dev -- fs-sync

# Sync large batch
terminus drush client.dev -- fs-sync --limit=500

# Reset and re-sync all
terminus drush client.dev -- fs-reset
terminus drush client.dev -- fs-sync --limit=500

# Delete from hub
terminus drush client.dev -- fs-delete

# Check last sync time
terminus drush client.dev -- state:get federated_search_client.last_sync

# View logs
terminus drush client.dev -- watchdog:show --type=federated_search_client
```

## 🔍 Search Examples

### Via curl

```bash
# Search all sites
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub/solr-proxy.php?q=drupal&rows=20"

# Search specific site
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub/solr-proxy.php?q=pantheon&site=marketing"

# With pagination
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub/solr-proxy.php?q=search&start=20&rows=10"

# With highlighting
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub/solr-proxy.php?q=drupal&hl=true"
```

### Via JavaScript

```javascript
// Simple search
fetch('https://hub/solr-proxy.php?q=drupal', {
  headers: { 'X-Federated-Search-Key': 'YOUR_KEY' }
})
.then(res => res.json())
.then(data => console.log(data.response.docs));

// See FEDERATED_SEARCH_WIDGET.html for full example
```

## 📊 Monitoring

### Check Health

```bash
# Total documents across all sites
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub/federated-search/status" | jq '.data.total_documents'

# Per-site breakdown
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub/federated-search/status" | jq '.data.sites'

# Check specific site
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub/federated-search/status?site_id=marketing"
```

### Sync Status

```bash
# On each client, check last sync
for site in site1 site2 site3; do
  echo "=== $site ==="
  terminus drush $site.live -- state:get federated_search_client.last_sync
done
```

## 🚀 Deploy to Production

```bash
# 1. Commit hub code
cd your-site-name
git add web/modules/custom/federated_search_hub web/solr-proxy.php
git commit -m "Add federated search hub"
git push

# 2. Deploy to Live
terminus env:deploy your-site-name.live --updatedb
terminus drush your-site-name.live -- en federated_search_hub -y

# 3. Update clients to point to Live
terminus drush client.live -- config:set \
  federated_search_client.settings hub_url \
  "https://your-site-name.pantheonsite.io"

# 4. Initial sync to Live hub
terminus drush client.live -- fs-reset
terminus drush client.live -- fs-sync --limit=500
```

## ⚠️ Troubleshooting

### "Invalid API key" errors

```bash
# Verify secret is accessible
terminus drush client.dev -- php-eval \
  "var_dump(pantheon_get_secret('FEDERATED_SEARCH_API_KEY'));"

# Should output the key, not NULL
# If NULL, wait a few minutes for Pantheon to propagate the secret
```

### Content not syncing

```bash
# Check if sync is enabled
terminus drush client.dev -- config:get \
  federated_search_client.settings enabled

# Check for errors
terminus drush client.dev -- watchdog:show \
  --type=federated_search_client --count=20

# Manually trigger sync
terminus drush client.dev -- fs-sync
```

### No search results

```bash
# Verify content was indexed
curl -H "X-Federated-Search-Key: $API_KEY" \
  "https://hub/federated-search/status?site_id=client"

# Check Solr index
terminus drush hub.dev -- search-api:status

# Reindex if needed
terminus drush hub.dev -- search-api:index
```

## 📚 Full Documentation

- **Complete Setup Guide:** `FEDERATED_SEARCH_SETUP.md`
- **Hub Module README:** `web/modules/custom/federated_search_hub/README.md`
- **Client Module README:** `web/modules/custom/federated_search_client/README.md`
- **Widget Example:** `FEDERATED_SEARCH_WIDGET.html`

## 💡 Pro Tips

**Faster initial sync:**
```bash
# Sync in parallel from multiple sites
for site in site1 site2 site3; do
  terminus drush $site.dev -- fs-sync --limit=500 &
done
wait
```

**Monitor all sites:**
```bash
# Check sync status across all sites
for site in site1 site2 site3; do
  echo "=== $site ==="
  terminus drush $site.live -- state:get federated_search_client.last_sync | \
    xargs -I {} date -r {} "+%Y-%m-%d %H:%M:%S"
done
```

**Quick re-index everything:**
```bash
# 1. Clear hub index
terminus drush hub.live -- search-api:clear

# 2. Reset and sync all clients
for site in site1 site2 site3; do
  terminus drush $site.live -- fs-reset
  terminus drush $site.live -- fs-sync --limit=1000 &
done
wait
```

## 🆘 Need Help?

Check logs first:
```bash
# Hub logs
terminus drush hub.dev -- watchdog:show --type=federated_search_hub

# Client logs
terminus drush client.dev -- watchdog:show --type=federated_search_client
```

Common issues are usually:
- API key not configured or wrong
- Hub URL incorrect
- Solr not enabled on hub
- Cron not running on clients
