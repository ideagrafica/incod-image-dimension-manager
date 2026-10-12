=== Incod Image Dimension Manager ===
Contributors: incod, ideagrafica
Tags: image resize, letterbox, crop, bulk resize, custom post type
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resize, fit and unify every image to fixed dimensions with a letterbox canvas or crop, no limits, and full support for all Custom Post Types.

== Description ==

**Incod Image Dimension Manager** unifies every image on your site to fixed dimensions, ensuring a consistent look on every page, archive and gallery.

Incod Image Dimension Manager is an original plugin developed, owned and maintained by inCOD (ideagrafica). "Incod" is the author's own brand: the plugin has no affiliation with, endorsement from, or connection to any other company or project of a similar name.

= Key features =

* Three resize modes: **Letterbox / Canvas**, **Hard Crop** with a focal point and **Proportional Scale**.
* **Customizable HEX fill canvas** (color picker) or **transparent** (PNG/WebP), always centered.
* **Dynamic detection of all public Custom Post Types**, WooCommerce included, with multiple selection.
* **Auto-resize on upload**: new images are fitted automatically when uploaded.
* **Asynchronous Bulk Resize** via AJAX: configurable batches, progress bar, percentage, counters, elapsed time and remaining-time estimate.
* **Timeout protection**: time budget per request, adaptive throttle between batches and a mutual-exclusion lock: the server is never saturated.
* **Pause**, **Resume** and **Cancel** at any moment, with no limit on the number of images.
* **Optional backup of the originals** with batched, progressive restore.
* **Imagick** engine with automatic **GD** fallback through `wp_get_image_editor()`.
* **Complete security**: capabilities, nonces, sanitization and escaping on every input and output.
* **Translatable**: text domain `incod-image-dimension-manager` and a `.pot` file in the `/languages` folder.

= Resize modes =

* **Letterbox / Canvas Padding** — fits the image inside the target dimensions without cropping and fills the empty space with a customizable (HEX color picker) or transparent canvas (PNG and WebP), with automatic centering.
* **Hard Crop** — crops the image to fill the target dimensions completely, with a selectable focal point (Center, Top, Bottom, Left, Right).
* **Proportional Scale** — scales the image proportionally without adding a canvas and without destructive cropping.
* Option to **prevent upscaling** of images smaller than the target.
* **JPEG quality** configurable from 1 to 100.
* Images that **already match** the target are skipped automatically, without rewriting the file.

= Custom Post Types =

* Dynamic detection of every registered public Custom Post Type (WooCommerce Products, posts, pages, portfolios, custom CPTs).
* Multiple selection through checkboxes in the settings panel.
* Support for Featured Images, child attachments, WooCommerce product galleries (`_product_image_gallery`) and product variation images.
* Explicit choice between the **whole Media Library** and **only the images used by the selected post types**.

= Automation and bulk processing =

* **Auto-resize on upload**: new images are resized automatically when uploaded (hook `wp_generate_attachment_metadata`), with a recursion guard.
* **Asynchronous Bulk Resize**: batch processing via AJAX with a smooth progress bar, percentage, counters, elapsed time and remaining-time estimate.
* **Configurable batches** of 1 to 50 images per request, with no limit on the total number of images.
* **Timeout protection**: every request works within a dedicated time budget; images not yet processed are deferred to the next request without losses or errors.
* **Adaptive throttle**: the pause between batches adapts to the load just processed, so the server is never saturated.
* **Mutual-exclusion lock**: only one processing session at a time, even with multiple tabs open.
* **Pause**, **Resume** and **Cancel**, with an error log (last 50 entries) and a stored, retrievable session state.
* PHP timeout and memory-limit increase applied only to the heavy AJAX requests that need it (queue, batch, restore); light requests and the rest of the site are never affected.
* **Optional backup of the original files** with batched restore (up to 25 folders or a time budget per request).

= Security and standards =

* `current_user_can( 'manage_options' )` checks on every screen and AJAX endpoint.
* CSRF protection with a nonce on every request.
* Input sanitization and systematic output escaping.
* PHP namespace and modular class architecture.
* Processing through `wp_get_image_editor()`: uses Imagick when available, with automatic GD fallback.
* `uninstall.php` for a clean removal of options, transients and backup folders.

= Requirements and compatibility =

* WordPress 5.0 or newer (tested up to 7.1).
* PHP 7.4 or newer.
* Imagick or GD (at least one of them, almost always already present).
* No external dependencies or third-party services.

== Installation ==

1. Upload the `incod-image-dimension-manager` folder to your site's `/wp-content/plugins/` directory (or install the plugin as a zip).
2. Activate the plugin from the WordPress "Plugins" screen.
3. Go to **Incod Image Dimension Manager → Settings** to set the target dimensions, mode and processing scope.
4. Open **Incod Image Dimension Manager → Bulk Resize** to process your existing images.

== Frequently Asked Questions ==

= Are the original images lost? =

No. If you enable "Keep a backup of the originals", every file is copied to `wp-content/uploads/idim-backups/` before being overwritten and can be restored from the Bulk Resize screen.

= What happens if the server does not have Imagick? =

The plugin uses GD automatically. The engine is chosen through the native `wp_get_image_editor()` function.

= Is there a limit to the number of images? =

No. The bulk process works in configurable batches (1-50 images per request) and has no quantitative limit. Every request has a dedicated time budget: images not yet processed are simply deferred to the next request.

= Can the bulk process get stuck or saturate the server? =

No. Every batch is stopped when the time budget is reached, the pause between batches adapts to the processed load (adaptive throttle) and a mutual-exclusion lock guarantees that only one session works at a time. If a request times out, the plugin retries it automatically.

= Can I close the page during the bulk process? =

You can, but it is better to keep it open: the processing loop lives in the browser. The session state is stored on the server anyway and is shown the next time you open the Bulk Resize screen.

= Does it support WooCommerce? =

Yes. The WooCommerce product is detected automatically among the Custom Post Types, and both the featured image and the product gallery are processed.

== Screenshots ==

1. Settings panel with dimensions, modes and Custom Post Type selection.
2. Bulk Resize with progress bar, statistics and pause/resume controls.
3. Settings panel with post types and automation options.

== Changelog ==

= 1.0.0 =
* First public release.
* Three modes: Letterbox/Canvas (HEX or transparent), Hard Crop with focal point and Proportional Scale.
* Dynamic detection of all public CPTs with multiple selection, whole Media Library, Featured Images, galleries and WooCommerce products.
* Auto-resize when new images are uploaded.
* Asynchronous Bulk Resize via AJAX with configurable batches, progress bar, percentage, counters, elapsed time and remaining-time estimate.
* Per-request time budget, adaptive throttle and mutual-exclusion lock against timeouts and server saturation.
* Pause, Resume and Cancel, error log and stored session state.
* Optional backup of the originals with batched restore.
* Imagick processing with GD fallback, complete security and translations (.pot).
* Explicit choice between the whole Media Library and only the images used by the selected post types.
* Featured images and product galleries are also in scope, including WooCommerce variations.
* Fixed the "Could not calculate resized image dimensions" error on upscales and images that already match the target.

== Upgrade Notice ==

= 1.0.0 =
First public release.
