# asset.kiwi Connect

Integrates Drupal with the [asset.kiwi](https://assetkiwi.com) digital asset management platform. Browse, import, and sync assets directly from the Drupal media library — with automatic usage tracking back to asset.kiwi so you always know where assets are used.

## Features

- **Media source plugin** — Manage asset.kiwi assets as native Drupal media entities
- **Visual picker widget** — Browse, search, and filter assets (by collection, tag, type, and semantic search mode) from a self-contained widget embedded in the Media Library add form and the field widget's dialog — no page reloads, no DAM API token in the browser
- **Field widget** — Select assets on any content type using the "asset.kiwi Browser" widget
- **Image formatter** — Render images directly from asset.kiwi variant URLs, with lazy/eager loading and optional link-to-original
- **Webhook sync** — asset.kiwi pushes updates (rename, alt text, description changes, deletion) to keep Drupal in sync
- **Usage tracking** — Whenever a media entity is inserted, updated, or deleted, asset.kiwi is notified so it knows where every asset lives
- **Drush integration** — Batch-import assets from the CLI with full filter support
- **Media Library integration** — A custom "Add" form replaces the default upload tab in the Media Library for asset.kiwi media types
- **File download on import** — Assets can be downloaded locally on import for use with Drupal image styles and responsive images

## Requirements

- **Drupal** 10 or 11 (`^10 || ^11`)
- **Drupal core modules**: Media (`media`), Media Library (`media_library`)
- **Drush** 12+ (optional, for the `assetkiwi:import-assets` command)
- An accessible **asset.kiwi instance** with a valid API token

## Installation

Install via Composer:

```bash
composer require assetkiwi/assetkiwi_connect
```

Or manually place the module in `modules/contrib/assetkiwi_connect/`.

Enable the module:

```bash
drush en assetkiwi_connect -y
```

Or visit **Extend** (`/admin/modules`) in the Drupal admin UI, find "asset.kiwi Connect" under the **Media** package, and enable it.

## Configuration

Navigate to **Configuration → Media → asset.kiwi Settings** (`/admin/config/media/assetkiwi`).

### API Connection

| Setting | Description |
|---------|-------------|
| **API Base URL** | The base URL of your asset.kiwi instance (e.g., `https://assetkiwi.ddev.site`). Do not include a trailing slash. |
| **API Token** | An API token from the asset.kiwi Settings page. Required for all API requests. |
| **Cache Lifetime** | How long to cache asset thumbnails locally, in seconds. Set to `0` to disable caching. Default: `3600` (1 hour). |

### OAuth2 Authentication (Per-User Mode)

asset.kiwi Connect supports two authentication modes, toggled via the **Authentication Mode** setting.

#### Shared Token Mode (default)

One API token is stored in module config. All Drupal users see the same assets and all API requests use the shared token. This is the simplest setup and works well for single-user Drupal sites or when all content editors share the same DAM access level.

#### Per-User OAuth Mode

Each Drupal user gets their own DAM identity via OAuth2 (authorization code grant with PKCE). When a user accesses an asset.kiwi-related page (media library, asset browser), they are prompted to connect their account. Once authorized, their personal access token is used for all API requests — different users can have different asset access scopes.

**Enabling per-user OAuth:**

1. In the asset.kiwi admin panel, create an OAuth2 client:
   - Navigate to **Settings → OAuth Clients** (or the OAuth2 management page)
   - Create a new client with the authorization code grant type
   - Set the redirect URI to `https://your-drupal-site.ddev.site/assetkiwi/oauth/callback`
   - Note the **Client ID** and **Client Secret**

2. In Drupal, navigate to **Configuration → Media → asset.kiwi Settings** (`/admin/config/media/assetkiwi`)

3. Under **OAuth2 Authentication**, set **Authentication Mode** to "Per-user OAuth"

4. Fill in the OAuth fields:

| Setting | Description |
|---------|-------------|
| **OAuth Client ID** | The client ID from the OAuth2 application registered in asset.kiwi |
| **OAuth Client Secret** | The client secret from the registered OAuth2 application |
| **Authorization URL** | The OAuth2 authorization endpoint (e.g., `https://dam.example.com/oauth/authorize`) |
| **Token URL** | The OAuth2 token endpoint (e.g., `https://dam.example.com/oauth/token`) |
| **OAuth Scopes** | The scopes to request. At minimum, `assets:read` is required for browsing and importing assets. Check `assets:write` if users need upload/modify permissions |

5. Save the configuration

**User experience:**

- When a user visits an asset.kiwi-related page (media library, asset browser widget), a warning message appears: "Your asset.kiwi account is not connected. Connect now to access your assets."
- Clicking **Connect now** redirects the user to the asset.kiwi authorization screen
- After approving, the user is redirected back to Drupal and their access token is stored
- Subsequent API requests use the user's personal token instead of the shared token
- If a user's token expires or is revoked, they are prompted to reconnect
- Users who have not yet connected fall back to the shared API token (if one is configured)

### Webhook

| Setting | Description |
|---------|-------------|
| **Webhook Secret** | A shared secret for verifying incoming webhook payloads from asset.kiwi. Must match the secret configured in the asset.kiwi webhook settings. Leave empty to disable webhook verification (not recommended). |

### Image Style Mapping

Map Drupal image styles to asset.kiwi variant names. When using the asset.kiwi Image formatter with a mapped variant, the module uses the pre-rendered variant URL from asset.kiwi rather than processing the image locally.

| Drupal Style | Default Variant | Description |
|-------------|-----------------|-------------|
| **Thumbnail** | `thumb` | Small preview image |
| **Medium** | `medium` | Mid-size display |
| **Large** | `large` | Full-size display |

These mappings are used by the media source plugin to populate `thumbnail_uri`, `thumb_url`, `medium_url`, and `large_url` metadata.

### Test Connection

Click **Test Connection** at the bottom of the settings form to verify your API URL and token. A success message confirms the API responded; a warning indicates connection issues.

## Setting Up a Media Type

After configuring the connection, create a media type that pulls from asset.kiwi.

### Step 1: Create the Media Type

1. Go to **Structure → Media types** (`/admin/structure/media`)
2. Click **Add media type**
3. Give it a name (e.g., "asset.kiwi Asset") and machine name (e.g., `assetkiwi_asset`)
4. Under **Media source**, select **asset.kiwi Asset**
5. Click **Save**

### Step 2: Configure the Source

On the media type edit page, under the **asset.kiwi Asset** source settings:

- **Allowed asset types** — Check the asset types this media type should accept (Images, Video, Audio, Documents). If none are checked, all types are allowed. When restricted, the browser widget and media library add form will only show and allow selecting assets of the chosen types.

### Step 3: Map Metadata Fields

Add fields to your media type that map to asset.kiwi metadata. The source plugin provides the following metadata attributes:

| Metadata Attribute | Description | Suggested Field Type |
|-------------------|-------------|---------------------|
| `uuid` | Asset UUID | Text (plain) |
| `original_name` | Original filename | Text (plain) |
| `mime_type` | MIME type | Text (plain) |
| `size` | File size in bytes | Number (integer) |
| `url` | Asset URL | Text (plain) |
| `alt_text` | Alt text | Text (plain) |
| `description` | Description | Text (long) |
| `width` | Width in pixels | Number (integer) |
| `height` | Height in pixels | Number (integer) |
| `thumbnail_uri` | Local thumbnail path | (automatically populated) |
| `thumb_url` | Thumbnail variant URL | Text (plain) |
| `medium_url` | Medium variant URL | Text (plain) |
| `large_url` | Large variant URL | Text (plain) |

In field settings, under **Metadata mapping**, map the asset.kiwi attributes to your field names. The source field (`field_assetkiwi_uuid`) is created automatically and stores the asset UUID.

**Tip**: For image assets, add an **Image** field (e.g., `field_media_image`). When an asset is imported and the downloaded file is an image, the module automatically populates this field with the downloaded file.

### Step 4: Configure Form Display

On the **Form display** tab for your media type, set the widget for the source field (`field_assetkiwi_uuid`) to **asset.kiwi Browser**. This replaces the plain text field with a "Browse DAM" button that opens the visual asset browser.

### Step 5: Configure Display

On the **Display** tab, set the formatter for the source field to **asset.kiwi Image**. Configure:
- **Variant name** — The asset.kiwi variant to render (e.g., `thumb`, `medium`, `large`). Leave empty for the original.
- **Image loading** — Lazy or eager.
- **Link to original asset** — Whether to wrap the image in a link to the original file.

## Usage

### Browsing and Importing from the Media Library

1. Edit any content that has a media reference field
2. Click **Add media** to open the Media Library
3. Select the tab for your asset.kiwi media type — the asset.kiwi picker widget is the *only* thing shown for this tab (Drupal's own "existing media" browse grid is suppressed for asset.kiwi media types, to avoid two separate, conflicting selection UIs for the same asset)
4. Click an asset's checkbox to select it (or several — up to the host field's configured cardinality), then click **Use selected**. Assets already imported as Drupal media show an **Already added** badge — selecting one of those references the existing media entity rather than creating a duplicate
5. For genuinely new assets, media entities are created (unsaved) with metadata pre-populated from asset.kiwi; review/fill in any required fields, then **Save and select**/**Save and insert** as usual

### Using the asset.kiwi Browser Field Widget

The "asset.kiwi Browser" widget can be placed on any entity type that has a plain text field — not just media. When configured:

1. A "Browse DAM" button appears in place of the text input
2. Clicking it opens the asset.kiwi picker widget in a Drupal dialog
3. Clicking an asset selects it immediately and closes the dialog, populating the hidden field with the asset UUID
4. A preview thumbnail and the "Replace asset" / "Remove" buttons appear for the selected asset — including on page load for content that already has a reference

Both entry points are powered by the same picker widget (`js/assetkiwi-picker.js`), which calls the module's own JSON endpoints (`/admin/assetkiwi/api/assets`, `/admin/assetkiwi/api/facets`) rather than talking to asset.kiwi directly — the API token never reaches the browser.

### Rendering Assets with the asset.kiwi Image Formatter

The formatter works on any string field containing an asset.kiwi asset UUID. It looks up the asset, resolves the configured variant URL, and renders an `<img>` tag. Variant dimensions (width/height) are applied automatically when available.

### Webhook Sync

asset.kiwi can push real-time updates to Drupal via webhooks. The endpoint is:

```
POST /assetkiwi/webhook
```

Configure your asset.kiwi instance to send webhooks to `https://your-drupal-site.ddev.site/assetkiwi/webhook`, and set the same secret in both asset.kiwi and the Drupal settings form.

Handled events:
- **`asset.updated`** — Syncs `name`, `field_assetkiwi_alt`, and `field_assetkiwi_description` for all media entities referencing the asset UUID
- **`asset.deleted`** — Unpublishes (does not delete) media entities referencing the deleted asset to preserve existing references
- **`webhook.test`** — Responds 200 OK for verification

Webhook payloads are verified using HMAC-SHA256 signature comparison. Unverified requests return a 403 response.

### Usage Tracking

Usage tracking is automatic. On every media entity insert, update, or delete, the module sends the asset UUID, entity type, entity ID, and canonical URL to asset.kiwi's usage endpoint (`POST /api/v1/assets/{uuid}/usage`). When a media entity is deleted, the usage record is removed via `DELETE`.

asset.kiwi then surfaces this data in its admin panel, showing which Drupal entities reference each asset.

## Drush Commands

### `assetkiwi:import-assets`

Import assets from asset.kiwi into Drupal as media entities.

```bash
drush assetkiwi:import-assets <bundle> [options]
```

**Arguments:**

| Argument | Description |
|----------|-------------|
| `bundle` | The media type machine name (e.g., `assetkiwi_asset`) |

**Options:**

| Option | Description |
|--------|-------------|
| `--collection` | Import from a specific collection slug |
| `--tag` | Import assets with a specific tag slug |
| `--type` | Filter by asset type: `image`, `video`, `audio`, or `document` |
| `--search` | Search query to filter assets |
| `--limit` | Maximum number of assets to import (`0` = unlimited) |
| `--dry-run` | Show what would be imported without actually importing |
| `--uuid` | Import a single asset by UUID |

**Examples:**

```bash
# Import all assets from the "marketing-images" collection
drush assetkiwi:import-assets assetkiwi_asset --collection=marketing-images

# Import up to 10 assets tagged "hero"
drush assetkiwi:import-assets assetkiwi_asset --tag=hero --limit=10

# Import only image assets with a search term
drush assetkiwi:import-assets assetkiwi_asset --type=image --search="banner"

# Preview what importing a specific UUID would do
drush assetkiwi:import-assets assetkiwi_asset --uuid=abc-123-def --dry-run

# Import a single asset
drush assetkiwi:import-assets assetkiwi_asset --uuid=abc-123-def
```

The command handles pagination automatically for large result sets. Existing assets (matched by UUID) are skipped. For image assets, the original file is downloaded and attached to the media entity.

## Permissions

| Permission | Description |
|-----------|-------------|
| **Administer asset.kiwi settings** | Configure the API URL, token, webhook secret, and image style mapping |
| **Browse asset.kiwi assets** | Use the picker widget (Media Library add form and field widget) and select assets for import |

Both permissions are typically assigned to content editors and administrators. The browse permission is required for the media library add form and the field widget to function.

## Extending

### Custom Field Mappings

The media source plugin (`AssetKiwiAsset`) provides metadata through `getMetadata()`. To add custom field mappings:

1. **Add a new field** to your asset.kiwi media type
2. **Map it** in the media type's field map under **Metadata mapping** — select the corresponding asset.kiwi attribute from the dropdown
3. If the attribute does not exist in `getMetadata()`, extend the source plugin:

```php
// mymodule/src/Plugin/media/Source/CustomAssetKiwiAsset.php
namespace Drupal\mymodule\Plugin\media\Source;

use Drupal\assetkiwi_connect\Plugin\media\Source\AssetKiwiAsset;

/**
 * @MediaSource(
 *   id = "custom_assetkiwi_asset",
 *   label = @Translation("Custom asset.kiwi Asset"),
 * )
 */
class CustomAssetKiwiAsset extends AssetKiwiAsset {

  public function getMetadataAttributes(): array {
    $attributes = parent::getMetadataAttributes();
    $attributes['copyright'] = $this->t('Copyright');
    $attributes['photographer'] = $this->t('Photographer');
    return $attributes;
  }

  public function getMetadata(MediaInterface $media, $name): mixed {
    $value = match ($name) {
      'copyright' => $this->fetchCustomField($media, 'copyright'),
      'photographer' => $this->fetchCustomField($media, 'photographer'),
      default => parent::getMetadata($media, $name),
    };
    return $value;
  }

}
```

### Custom Widget or Formatter

The "asset.kiwi Browser" widget and "asset.kiwi Image" formatter work on any string field containing an asset UUID. You can use them on non-media entity types (nodes, paragraphs, blocks) by adding a plain text field and setting the widget/formatter accordingly.

### Reacting to Webhook Events

Webhook events are dispatched through `\Drupal\assetkiwi_connect\Controller\WebhookController`. To react to additional events or extend handling, use Drupal's event system:

```php
// mymodule.services.yml
services:
  mymodule.webhook_subscriber:
    class: Drupal\mymodule\EventSubscriber\WebhookSubscriber
    tags:
      - { name: event_subscriber }
```

Or alter the webhook controller via a service decorator if deeper integration is needed.

## Troubleshooting

### Connection Refused / API Errors

**Symptom**: "Could not load assets from asset.kiwi." message in the picker widget, or empty asset grids.

**Checklist**:
1. Verify the **API Base URL** is correct and reachable from the Drupal server
2. Verify the **API Token** has not expired or been revoked
3. Check Drupal's recent log messages (`/admin/reports/dblog`) for `assetkiwi_connect` errors
4. Test connectivity from the server: `curl -H "Authorization: Bearer TOKEN" https://your-dam.example.com/api/v1/assets`
5. Use the **Test Connection** button on the settings form

### Empty Picker / No Assets Found

**Symptom**: The picker widget loads but shows "No assets found" even though assets exist.

**Checklist**:
1. Confirm the API token has access to the expected assets
2. Check that any type restrictions on the media type source configuration aren't filtering out assets unexpectedly
3. Try clearing the type/collection/tag filters in the picker
4. If using the `document` type filter, ensure your asset.kiwi instance version supports the `type` parameter for documents (the module automatically translates `mime_type=document` to `type=document`)
5. Check the **Search mode** — if set to "Semantic" or "Hybrid", verify your asset.kiwi instance has semantic search enabled

### Webhook 403 Errors

**Symptom**: asset.kiwi reports 403 responses when sending webhooks to Drupal.

**Checklist**:
1. Ensure the **Webhook Secret** is configured in Drupal settings (not empty)
2. Verify the secret in Drupal matches the secret in asset.kiwi's webhook configuration **exactly** (case-sensitive, no extra whitespace)
3. Check that the webhook URL is reachable from the asset.kiwi server (not blocked by firewall, VPN, or basic auth)
4. If Drupal is behind HTTP basic auth, the webhook endpoint is excluded from auth by default (route has `_access: 'TRUE'`)
5. Check Drupal's logs for "Webhook signature verification failed" entries
6. Test manually:

```bash
PAYLOAD='{"event":"webhook.test","data":{}}'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "your-secret" | cut -d' ' -f2)
curl -X POST -H "X-Webhook-Signature: $SIGNATURE" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD" \
  https://your-drupal-site.ddev.site/assetkiwi/webhook
```

### Image Styles Not Working

**Symptom**: Drupal image styles don't generate derivatives for asset.kiwi images.

**Solution**: The asset.kiwi Image formatter renders images from asset.kiwi's variant URLs by default (bypassing Drupal image styles). To use Drupal image styles, you have two options:
1. Use the imported local file — download the asset file on import (automatic for image assets via the media library add form) and use Drupal's standard Image formatter on the `field_media_image` field
2. Map Drupal image styles to asset.kiwi variants in the settings form, so each style pulls the corresponding variant URL

### Import Failures

**Symptom**: Drush `assetkiwi:import-assets` reports errors or skips assets.

**Checklist**:
1. Verify the media type exists and uses the asset.kiwi Asset source plugin
2. Run with `--dry-run` first to preview what would happen
3. Check that assets aren't already imported (duplicate UUIDs are skipped)
4. For file download failures, check write permissions on `public://assetkiwi_files/`
5. Review Drupal's logs for detailed error messages

## Migration Submodule (assetkiwi_connect_migrate)

The `assetkiwi_connect_migrate` submodule migrates locally-stored Drupal media files to asset.kiwi.

### Pipeline
The migration follows this pipeline for each media item:
1. **Hash** — Computes a content hash of the local file
2. **Upload** — Uploads the file to asset.kiwi (content-addressed, duplicates are reused)
3. **Verify** — Confirms the remote file size matches the local file
4. **Offload** — Marks the media as offloaded; rendering is swapped to use asset.kiwi URLs

Local files are NOT deleted during migration. Use the separate purge step to remove local files after verifying.

### Usage

#### Via Drush
```bash
drush assetkiwi-migrate:scan    # Find eligible media files
drush assetkiwi-migrate:run     # Migrate all pending items
drush assetkiwi-migrate:status  # View migration status counts
drush assetkiwi-migrate:purge   # Delete local files for offloaded media
```

#### Via Admin UI
Navigate to **Media → AssetKiwi Migration**. Use the Scan, Migrate, and Purge buttons.

### Tracking Table
Migration status is tracked in the `assetkiwi_connect_migrate` database table with statuses: `pending`, `uploading`, `uploaded`, `verified`, `offloaded`, `failed`, `purged`.

### Important
- Requires the main `assetkiwi_connect` module to be installed and configured
- Migration is **idempotent** — re-running on already-migrated items is safe
- The original Drupal file field values are never modified; rendering is swapped at the view layer

---

**Links**:
- [asset.kiwi](https://assetkiwi.com)
- [GitHub Repository](https://github.com/assetkiwi/integration-drupal)
- [Issue Tracker](https://github.com/assetkiwi/integration-drupal/issues)
