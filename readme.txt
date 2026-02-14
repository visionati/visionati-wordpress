=== Visionati ===
Contributors: visionati
Tags: alt text, ai, accessibility, woocommerce, product descriptions
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered alt text, captions, and product descriptions. Choose from leading AI models.

== Description ==

Visionati generates image alt text, captions, and product descriptions powered by your choice of AI model. Pick the model that works best for your content: Claude (Anthropic), Gemini (Google), OpenAI, Grok (xAI), Jina AI, and more.

**Why alt text matters:**

* Screen readers depend on alt text to describe images to visually impaired users
* Search engines use alt text to understand and index your images
* Missing alt text hurts both accessibility compliance and SEO rankings
* Most WordPress sites have hundreds of images with no alt text at all

**What Visionati does:**

* **Preview before apply** — generate a description, review it, then apply or discard. No surprises.
* **Generates alt text, captions, and descriptions** for any image in your Media Library, one at a time or in bulk
* **Auto-generates on upload** so new images get alt text immediately
* **WooCommerce product descriptions** from product images, including short and long descriptions with product context. Apply each description independently or both at once.
* **Pick your AI model** with optional per-context overrides. One global default, override any context individually. Gemini for fast media fields, Claude for WooCommerce product descriptions.
* **Debug mode** — toggle in settings, traces to the browser console. No server access needed.
* **12 built-in roles** shape the AI output for different contexts: Alt Text, Artist, Caption, Comedian, Critic, Ecommerce, General, Inspector, Promoter, Prompt, Realtor, and Tweet
* **Custom prompts** for full control over what the AI generates
* **160+ languages** supported for output

**How it works:**

1. Install the plugin and enter your Visionati API key
2. Pick your AI model (default: Gemini). Optionally override the model per context in settings.
3. Click **Alt Text**, **Caption**, or **Description** on any image. A preview appears. Review it, then Apply or Discard.
4. The right AI role is used automatically for each field. Bulk generation processes your entire library without previews.

Images are sent securely as base64 data directly from your server. This works everywhere: localhost, staging sites, password-protected sites, and private networks. The Visionati API never needs to reach back to your WordPress site.

**Credits:**

Visionati uses a credit-based system. See [pricing details](https://visionati.com/pricing/) on the website. [Sign up for an account](https://api.visionati.com/signup) to get started.

**Third-Party Service:**

This plugin sends image data to the [Visionati API](https://api.visionati.com) for analysis. By using this plugin, you agree to the Visionati [Terms of Service](https://visionati.com/terms/) and [Privacy Policy](https://visionati.com/privacy/). No data is sent until you configure an API key and initiate an analysis.

== Installation ==

1. Upload the `visionati` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings → Visionati and enter your API key
4. Click "Verify" to confirm the connection
5. Select your preferred AI models and language
6. Start generating alt text from the Media Library or the Bulk Generate page

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. Visionati is a paid API service. [Sign up at api.visionati.com](https://api.visionati.com/signup) to get your API key and purchase credits.

= How much does it cost? =

Visionati uses a credit-based system. Cost depends on which AI model you choose. You can use different models for different contexts (e.g. Gemini for alt text, Claude for product descriptions). See [pricing details](https://visionati.com/pricing/) on the website.

= Does it work on localhost and staging sites? =

Yes. The plugin sends images as base64 data directly from your server, so the Visionati API never needs to access your site. This works behind firewalls, on localhost, staging environments, and password-protected sites.

= What image formats are supported? =

JPEG, PNG, GIF, WebP, and BMP.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active, the plugin adds a "Generate Descriptions" button to product edit screens and a bulk action to the Products list. It generates both short and long product descriptions using the product image, name, categories, and attributes for context. Each description can be applied independently or both at once.

= Can I customize what the AI generates? =

Yes. Choose from 12 built-in roles (Alt Text, Ecommerce, General, etc.) or write your own custom prompts. Each context (alt text, caption, description, WooCommerce) can use a different role and a different AI model.

= What AI models are available? =

Choose from Claude (Anthropic), Gemini (Google), OpenAI, Grok (xAI), Jina AI, LLaVA, and BakLLaVA. Pick one global default and optionally override per context. Default: Gemini.

= Will it overwrite my existing alt text? =

Not by default. There is an "Overwrite Existing" setting you can enable if you want to regenerate alt text for images that already have it.

= What languages are supported? =

Over 160 languages. Set your preferred language in the plugin settings and all AI-generated text will be in that language.

== Screenshots ==

1. Settings page with API key, model selection, and context settings
2. Media Library with generated alt text, caption, and description previews
3. Bulk Generate page with progress bar and results log
4. WooCommerce product edit screen with generated short and long descriptions
5. Bulk Generate mobile view
6. WooCommerce Bulk Descriptions page with status filters and progress bar
7. WooCommerce Bulk Descriptions mobile view

== Changelog ==

= 1.0.0 =
* Initial release
* Preview before apply: generate a description, review it, then apply or discard
* Single AI model default with optional per-context overrides
* Per-field generation: separate Alt Text, Caption, and Description buttons in Media Library
* Each button uses the appropriate role (Alt Text, Caption, General) for best results
* Bulk generation with field selection (Alt Text, Caption, Description) and progress tracking
* Confirmation dialog before bulk operations with image/product count and overwrite warning
* Resume preserves previous log entries and accumulated counters
* Only images that need work are queued; images with existing content are skipped
* Automatic stop on credit exhaustion
* Auto-generate on image upload (configurable per field); multiple fields run in parallel
* Auto-generate failures surfaced as admin notices (not just error logs)
* WooCommerce product description generation (short and long) with product context
* WooCommerce per-field apply: accept short and long descriptions independently or both at once
* WooCommerce bulk action for product descriptions; also generates alt text for featured images if missing
* WooCommerce bulk status filter: choose which product statuses to include (Published, Draft, Pending, Private) with live-updating counts
* 12 context-aware roles: Alt Text, Artist, Caption, Comedian, Critic, Ecommerce, General, Inspector, Promoter, Prompt, Realtor, Tweet
* Custom prompt support with WooCommerce placeholders ({product_name}, {categories}, {price})
* 160+ language support
* 7 AI models: Claude, Gemini, OpenAI, Grok, Jina AI, LLaVA, BakLLaVA
* Debug mode: toggle in settings, traces to browser console (F12)
* Docker development environment for local testing
* Clean uninstall: all plugin data removed from database when plugin is deleted

== Upgrade Notice ==

= 1.0.0 =
Initial release.