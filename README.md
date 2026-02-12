# Visionati

AI-powered image alt text, captions, and product descriptions for WordPress and WooCommerce.

Powered by the [Visionati API](https://api.visionati.com). Choose from AI models by Anthropic, Google, OpenAI, xAI, and others to generate descriptions tuned to your needs.

## Features

- **Preview Before Apply**: Generate a description, review it, then apply or discard. No surprises.
- **Alt Text, Captions, and Descriptions**: Dedicated per-field buttons. Each uses the right AI role for that field.
- **Bulk Generate**: Generate alt text, captions, and descriptions for your entire library. Pick which fields to generate, filter by images missing selected fields, and track progress in real time with stop/resume.
- **Auto-Generate on Upload**: Automatically generate selected fields when images are uploaded.
- **WooCommerce Product Descriptions**: Generate short and long product descriptions from the featured image, with product name, categories, and attributes included for context. Preview each description independently. Apply one, both, or discard. Dedicated bulk page under Products.
- **12 Built-in Roles**: Alt Text, Artist, Caption, Comedian, Critic, Ecommerce, General, Inspector, Promoter, Prompt, Realtor, and Tweet.
- **Custom Prompts**: Write your own instructions per context (alt text, caption, media description, WooCommerce).
- **160+ Languages**: Generate descriptions in any supported language.
- **Context-Aware Defaults**: Alt text uses the Alt Text role, captions use Caption, WooCommerce uses Ecommerce, media descriptions use General. Each context can use a different role and a different AI model.
- **Base64 Encoding**: Images are sent directly from your server as base64 data. Works on localhost, staging, password-protected sites, and behind firewalls.
- **Debug Mode**: Toggle in settings, traces to the browser console. No server access needed.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A [Visionati API key](https://api.visionati.com/signup)
- WooCommerce (optional, for product description features)

## Installation

1. Upload the `visionati` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to **Settings > Visionati** and enter your API key
4. Click **Verify** to confirm the connection
5. Select your preferred AI model and language

## Usage

### Single Image

Open any image in the Media Library. Three buttons appear under the Visionati label: **Alt Text**, **Caption**, and **Description**. Click any button to generate. A preview appears below with the generated text. Review it, then click **Apply** to save it to the field or **Discard** to throw it away. Each button works independently. You can generate and preview multiple fields at once.

### Bulk Generate

Go to **Media > Bulk Generate**. Check which fields you want to generate (Alt Text, Caption, Description), then click **Start**. A confirmation dialog shows how many images will be processed and warns if overwrite is enabled. Only images that need work for the selected fields are queued. Images that already have content for all selected fields are skipped unless you enable **Overwrite Existing** in settings. Progress is shown in real time with a log of results per image. You can stop and resume at any time. Credit exhaustion is detected automatically.

### WooCommerce

On any product edit screen, the Visionati meta box lets you generate short and long descriptions from the featured image. Click **Generate Descriptions** to preview both. Each description has its own **Apply** button so you can accept them independently. Or use **Apply to Product** to save whatever hasn't been applied yet. **Discard** clears everything.

During bulk processing, alt text for the featured image is also generated if missing. A dedicated **Bulk Descriptions** page is available under the Products menu, and a bulk action on the Products list redirects there for AJAX-powered processing. Bulk includes products in all statuses (publish, draft, pending, private), not just published.

### Auto-Generate

Enable **Auto-generate on Upload** in settings to automatically generate alt text, captions, and/or descriptions for every image you upload. Each field is configurable independently. When multiple fields are enabled, they run in parallel, so three fields take roughly the same time as one.

## Configuration

All settings are under **Settings > Visionati**:

| Section | What |
|---------|------|
| **API Connection** | API key (password field) with Verify button |
| **API Settings** | AI model dropdown (single backend, default: Gemini) and language (160+ languages) |
| **Context Settings** | Per-context role and optional model override for Alt Text, Caption, Media Description, and WooCommerce |
| **Custom Prompts** | Optional prompt per context (overrides the selected role). WooCommerce supports `{product_name}`, `{categories}`, and `{price}` placeholders. |
| **Automation** | Auto-generate on upload (per field), overwrite existing (per field), WooCommerce product context toggle |
| **Debug** | Debug Mode checkbox. Logs PHP and JS traces to the browser console (F12). |

WooCommerce settings only appear when WooCommerce is active.

## Credits

Visionati uses a credit-based system. Cost depends on which AI model you choose. See [pricing details](https://visionati.com/pricing/) on the website. [Sign up](https://api.visionati.com/signup) and purchase credits to get started.

## Development

No build step. Plain PHP, JS, and CSS.

### Local Development with Docker

The easiest way to develop and test locally. Requires [Docker Desktop](https://www.docker.com/products/docker-desktop/).

```bash
# First run: start containers, install WordPress + WooCommerce, activate Visionati
docker-compose up -d
docker-compose run wpcli

# After that, just start the containers
docker-compose up -d
```

Then open `http://localhost:9090/wp-admin` (user: `admin`, password: `admin`).

Edit any plugin file locally and refresh the browser. Changes are live immediately. `WP_DEBUG` and `WP_DEBUG_LOG` are enabled by default. Asset URLs use `filemtime()` when `WP_DEBUG` is on, so CSS/JS changes bust the browser cache automatically.

```bash
# Stop containers (data is preserved)
docker-compose down

# Destroy everything and start fresh
docker-compose down -v
```

### Debug Mode

Enable **Debug Mode** in Settings → Visionati to log diagnostic information to the browser console. Open developer tools (F12 → Console) and look for `[Visionati]` entries. Both PHP-side and JS-side traces appear in the console. Server-side entries are attached to every AJAX response and logged as a collapsed group.

### Other Tools

```bash
# Lint PHP (requires PHPCS with WordPress standards)
phpcs --standard=WordPress visionati.php includes/

# Generate translation template (requires WP-CLI)
wp i18n make-pot . languages/visionati.pot
```

## License

GPL v2 or later. See [LICENSE](LICENSE).