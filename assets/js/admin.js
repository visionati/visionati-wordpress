/**
 * Visionati Admin JavaScript
 *
 * Handles: API key verification, single image analysis,
 * bulk processing with progress UI, and WooCommerce description generation.
 *
 * @package Visionati
 */

(function ($) {
	'use strict';

	var admin = visionatiAdmin || {};
	var i18n = admin.i18n || {};
	var isDebug = admin.debug || false;

	function log() {
		if (isDebug && typeof console !== 'undefined' && console.log) {
			var args = Array.prototype.slice.call(arguments);
			args.unshift('[Visionati]');

			console.log.apply(console, args);
		}
	}

	/**
	 * Log the PHP-side debug trace from an AJAX response.
	 *
	 * When debug mode is on, the PHP wrappers attach a '_debug' array
	 * to every AJAX response. This function logs each entry so the
	 * full server-side trace appears in the browser console alongside
	 * the JS-side trace.
	 */
	function logServerTrace(data) {
		if (!isDebug || !data || !data._debug || !data._debug.length) {
			return;
		}
		console.groupCollapsed('[Visionati] Server trace (' + data._debug.length + ' entries)');
		data._debug.forEach(function (entry) {
			if (entry.context) {
				console.log(entry.message, entry.context);
			} else {
				console.log(entry.message);
			}
		});
		console.groupEnd();
	}
	var bulkState = {
		running: false,
		ids: [],
		current: 0,
		generated: 0,
		skipped: 0,
		errors: 0,
	};

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	function isCreditError(message) {
		if (!message) {
			return false;
		}
		var lower = message.toLowerCase();
		return lower.indexOf('no credits') !== -1 || lower.indexOf('insufficient credits') !== -1;
	}

	function setStatus($el, message, type) {
		$el.attr('class', 'visionati-status ' + type).text(message);
	}

	function showNotice(message, type) {
		type = type || 'error';
		var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p></p></div>');
		$notice.find('p').text(message);
		var $dismissBtn = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
		$dismissBtn.on('click', function () { $notice.remove(); });
		$notice.append($dismissBtn);

		var $target = $('.visionati-bulk-controls, .wrap > h1').first();
		if ($target.length) {
			$target.before($notice);
		} else {
			$('.wrap').prepend($notice);
		}
	}

	function updateCreditsDisplay(credits) {
		if (typeof credits === 'undefined' || credits === null) {
			return;
		}
		var text = (i18n.creditsRemaining || '%d credits remaining').replace('%d', credits);
		$('.visionati-credits-remaining').text(text).show();
	}

	// -------------------------------------------------------------------------
	// API Key Verification
	// -------------------------------------------------------------------------

	function initVerifyKey() {
		var $button = $('#visionati-verify-key');
		if (!$button.length) {
			return;
		}

		$button.on('click', function () {
			var $status = $('#visionati-verify-status');
			var apiKey = $('#visionati_api_key').val();
			log('verify key: starting');

			if (!apiKey) {
				setStatus($status, i18n.connectionError || 'Connection failed.', 'error');
				return;
			}

			setStatus($status, i18n.verifying || 'Verifying...', 'loading');
			$button.prop('disabled', true);

			$.post(admin.ajaxUrl, {
				action: 'visionati_verify_key',
				nonce: admin.nonce,
				api_key: apiKey,
			})
				.done(function (response) {
					log('verify key: response', response);
					logServerTrace(response.data);
					if (response.success) {
						setStatus($status, response.data.message || i18n.connected, 'success');
					} else {
						setStatus(
							$status,
							response.data.message || i18n.connectionError,
							'error'
						);
					}
				})
				.fail(function (jqXHR, textStatus, errorThrown) {
					log('verify key: AJAX failed', textStatus, errorThrown);
					setStatus($status, i18n.connectionError || 'Connection failed.', 'error');
				})
				.always(function () {
					$button.prop('disabled', false);
				});
		});
	}

	// -------------------------------------------------------------------------
	// Single Image Analysis (Media Library)
	// -------------------------------------------------------------------------

	function initMediaButtons() {
		// Force the compat section (where attachment_fields_to_edit renders)
		// to load when the media modal selects an attachment. On WooCommerce
		// product pages, the featured image modal sometimes opens without
		// fetching the compat HTML on the first load. Listening for the
		// selection event and calling fetch() ensures the Visionati buttons
		// appear immediately instead of requiring a click-away-and-back.
		if (typeof wp !== 'undefined' && wp.media) {
			wp.media.view.Attachment.Details = wp.media.view.Attachment.Details.extend({
				ready: function () {
					// Call the original ready method.
					wp.media.view.Attachment.Details.__super__.ready.apply(this, arguments);

					// If the compat node is empty, re-fetch the model to
					// trigger the compat AJAX request.
					var compat = this.model.get('compat');
					if (!compat || !compat.item) {
						this.model.fetch();
					}
				},
			});
		}

		// Use event delegation for buttons inside modals and list views.
		// Generate button: calls the API and shows a preview (no saving).
		$(document).on('click', '.visionati-media-actions .button', function (e) {
			e.preventDefault();

			var $button = $(this);
			var attachmentId = $button.data('attachment-id');
			var context = $button.data('context');

			if (!attachmentId || !context || $button.hasClass('is-loading')) {
				return;
			}

			var $previews = $button.closest('.compat-field-visionati, .visionati-field-row')
				.find('.visionati-media-previews[data-attachment-id="' + attachmentId + '"]');
			if (!$previews.length) {
				$previews = $('.visionati-media-previews[data-attachment-id="' + attachmentId + '"]');
			}

			var contextLabel = fieldLabels[context] || context;
			log('analyze: starting', { attachmentId: attachmentId, context: context });
			$button.addClass('is-loading').prop('disabled', true);

			// Remove any existing preview for this context.
			$previews.find('.visionati-media-preview[data-context="' + context + '"]').remove();

			$.post(admin.ajaxUrl, {
				action: 'visionati_analyze',
				nonce: admin.nonce,
				attachment_id: attachmentId,
				context: context,
			})
				.done(function (response) {
					log('analyze: response', { context: context, success: response.success, data: response.data });
					logServerTrace(response.data);
					if (response.success) {
						var desc = response.data.description || '';
						var creditsMsg = '';
						if (response.data.credits !== undefined && response.data.credits !== null) {
							creditsMsg = ' (' + (i18n.creditsRemaining || '%d credits remaining').replace('%d', response.data.credits) + ')';
						}
						addMediaPreview($previews, attachmentId, context, desc, creditsMsg);
					} else {
						var $error = $('<div class="visionati-media-preview">').attr('data-context', context).append(
							$('<span class="visionati-media-preview-status error">').text(contextLabel + ': ' + (response.data.message || 'Error'))
						);
						$previews.append($error);
					}
				})
				.fail(function (jqXHR, textStatus, errorThrown) {
					log('analyze: AJAX failed', { context: context, status: textStatus, error: errorThrown });
					var $error = $('<div class="visionati-media-preview">').attr('data-context', context).append(
						$('<span class="visionati-media-preview-status error">').text(contextLabel + ': ' + (i18n.error || 'Error'))
					);
					$previews.append($error);
				})
				.always(function () {
					$button.removeClass('is-loading').prop('disabled', false);
				});
		});

		// Apply button on a preview: saves that one field.
		$(document).on('click', '.visionati-media-apply-btn', function (e) {
			e.preventDefault();

			var $button = $(this);
			var $preview = $button.closest('.visionati-media-preview');
			var $status = $preview.find('.visionati-media-preview-status');
			var attachmentId = $button.data('attachment-id');
			var context = $button.data('context');
			var description = $preview.data('description');
			var contextLabel = fieldLabels[context] || context;

			if (!attachmentId || !context || !description) {
				return;
			}

			log('apply field: starting', { attachmentId: attachmentId, context: context });
			$button.prop('disabled', true);
			$status.text(i18n.processing || 'Applying...').attr('class', 'visionati-media-preview-status loading');

			$.post(admin.ajaxUrl, {
				action: 'visionati_apply_field',
				nonce: admin.nonce,
				attachment_id: attachmentId,
				context: context,
				description: description,
			})
				.done(function (response) {
					log('apply field: response', response.data);
					logServerTrace(response.data);
					if (response.success) {
						$status.text(contextLabel + ': ' + (i18n.complete || 'Applied.')).attr('class', 'visionati-media-preview-status success');
						$preview.find('.visionati-media-apply-btn, .visionati-media-discard-btn').hide();
						// Update the visible field in the current view.
						updateMediaFields(attachmentId, { description: description }, context);
					} else {
						$status.text((i18n.error || 'Error') + ': ' + (response.data.message || '')).attr('class', 'visionati-media-preview-status error');
					}
				})
				.fail(function () {
					$status.text(i18n.error || 'Error').attr('class', 'visionati-media-preview-status error');
				})
				.always(function () {
					$button.prop('disabled', false);
				});
		});

		// Discard button on a preview: removes the preview.
		$(document).on('click', '.visionati-media-discard-btn', function (e) {
			e.preventDefault();
			$(this).closest('.visionati-media-preview').remove();
		});
	}

	/**
	 * Build and insert a preview block for a generated description.
	 */
	function addMediaPreview($container, attachmentId, context, description, creditsMsg) {
		var contextLabel = fieldLabels[context] || context;

		var $preview = $('<div class="visionati-media-preview" data-context="' + context + '">')
			.data('description', description);

		$preview.append(
			$('<div class="visionati-media-preview-header">').append(
				$('<strong>').text(contextLabel + ':'),
				creditsMsg ? $('<span class="visionati-media-preview-credits">').text(creditsMsg) : ''
			)
		);

		var displayText = (context === 'alt_text') ? description.substring(0, 125) : description;
		if (context === 'description') {
			$preview.append($('<div class="visionati-media-preview-text">').html(displayText));
		} else {
			$preview.append($('<div class="visionati-media-preview-text">').text(displayText));
		}

		$preview.append(
			$('<div class="visionati-media-preview-actions">').append(
				$('<button type="button" class="button visionati-media-apply-btn">')
					.attr({ 'data-attachment-id': attachmentId, 'data-context': context })
					.text(i18n.apply || 'Apply'),
				$('<button type="button" class="button visionati-media-discard-btn">')
					.text(i18n.discard || 'Discard'),
				$('<span class="visionati-media-preview-status">')
			)
		);

		$container.append($preview);
	}

	/**
	 * Update the relevant field in the Media Library UI after generation.
	 *
	 * WordPress uses two different field systems:
	 * - Grid view modal: Backbone-bound fields with data-setting="..." attributes
	 * - List view / edit page: traditional inputs with name="attachments[ID][field]"
	 *
	 * We try both approaches for each field.
	 */
	function updateMediaFields(attachmentId, data, context) {
		if (!data || !data.description) {
			log('updateMediaFields: no description in data, skipping', { attachmentId: attachmentId, context: context, data: data });
			return;
		}

		log('updateMediaFields: starting', { attachmentId: attachmentId, context: context, descriptionLength: data.description.length, fields: data.fields });

		var text = data.description;
		var fieldValue = (context === 'alt_text') ? text.substring(0, 125) : text;

		// Data is already saved server-side by the AJAX handler. These updates
		// are purely visual. We set the textarea value directly and update the
		// Backbone model silently (no re-render) so the model stays in sync
		// for any future re-renders without destroying in-flight AJAX state.

		var settingName = {
			alt_text: 'alt',
			caption: 'caption',
			description: 'description',
		}[context];

		if (!settingName) {
			return;
		}

		// 1. Grid modal: find the field via data-setting (works for both
		//    single-column and two-column views, avoids stale ID matches).
		var $wrapper = $('[data-setting="' + settingName + '"]');
		var $field = $wrapper.find('textarea, input[type="text"]');
		if ($field.length) {
			$field.val(fieldValue);
			log('updateMediaFields: grid modal field set via val()', { settingName: settingName, fieldId: $field.attr('id') || '(none)' });

			// If TinyMCE is active on this textarea (some configs add a
			// visual editor to the description field), update it too.
			if (typeof tinymce !== 'undefined') {
				var fieldId = $field.attr('id');
				if (fieldId) {
					var mceEditor = tinymce.get(fieldId);
					if (mceEditor && !mceEditor.isHidden()) {
						mceEditor.setContent(fieldValue);
						log('updateMediaFields: grid modal TinyMCE updated', { fieldId: fieldId });
					}
				}
			}
		} else {
			log('updateMediaFields: grid modal field NOT found for', settingName);
		}

		// Update Backbone model silently — no re-render, no compat reload,
		// no destroyed buttons. The model stays in sync so future re-renders
		// (e.g. navigating between images) show the correct values.
		try {
			if (typeof wp !== 'undefined' && wp.media && wp.media.attachment) {
				var attachment = wp.media.attachment(attachmentId);
				if (attachment) {
					attachment.set(settingName, fieldValue, { silent: true });
				}
			}
		} catch (e) {
			log('updateMediaFields: Backbone model update failed (non-fatal)', e.message);
		}

		// 2. Standalone attachment edit page: different field structure.
		//    Set both TinyMCE and the raw textarea so the value is correct
		//    regardless of which tab (Visual/Text) is active.
		if (context === 'alt_text') {
			var $altField = $('#attachment_alt');
			if ($altField.length) {
				$altField.val(fieldValue);
				log('updateMediaFields: standalone #attachment_alt set');
			}
		}
		if (context === 'caption') {
			if (typeof tinymce !== 'undefined') {
				var captionEditor = tinymce.get('excerpt');
				if (captionEditor && !captionEditor.isHidden()) {
					captionEditor.setContent(fieldValue);
					log('updateMediaFields: standalone TinyMCE excerpt set');
				} else {
					log('updateMediaFields: standalone TinyMCE excerpt not found or hidden');
				}
			}
			var $captionField = $('#attachment_caption, #excerpt');
			if ($captionField.length) {
				$captionField.val(fieldValue);
				log('updateMediaFields: standalone caption textarea set', { matched: $captionField.length });
			}
		}
		if (context === 'description') {
			if (typeof tinymce !== 'undefined') {
				var descEditor = tinymce.get('content') || tinymce.get('attachment_content');
				if (descEditor && !descEditor.isHidden()) {
					descEditor.setContent(fieldValue);
					log('updateMediaFields: standalone TinyMCE description set', { editorId: descEditor.id });
				} else {
					log('updateMediaFields: standalone TinyMCE description not found or hidden');
				}
			}
			var $contentField = $('#content, #attachment_content');
			if ($contentField.length) {
				$contentField.val(fieldValue);
				log('updateMediaFields: standalone description textarea set', { id: $contentField.attr('id') });
			}
		}

		// 3. List view: name-based form fields.
		var nameMap = {
			alt_text: '_wp_attachment_image_alt',
			caption: 'post_excerpt',
			description: 'post_content',
		};
		var fieldName = nameMap[context];
		if (fieldName) {
			$('[name="attachments[' + attachmentId + '][' + fieldName + ']"]').val(fieldValue);
		}
	}

	// -------------------------------------------------------------------------
	// Bulk Generate (Media → Bulk Generate page)
	// -------------------------------------------------------------------------

	function getSelectedBulkContexts() {
		var contexts = [];
		$('input[name="visionati_bulk_context"]:checked').each(function () {
			contexts.push($(this).val());
		});
		return contexts;
	}

	var fieldLabels = i18n.fieldLabels || {
		alt_text: 'alt text',
		caption: 'caption',
		description: 'description'
	};

	function formatFieldNames(fields) {
		if (!fields || !fields.length) {
			return '';
		}
		return fields.map(function (f) { return fieldLabels[f] || f; }).join(', ');
	}

	function initBulkGenerate() {
		var $startBtn = $('#visionati-bulk-start');
		var $stopBtn = $('#visionati-bulk-stop');

		if (!$startBtn.length) {
			return;
		}

		$startBtn.on('click', function () {
			if (bulkState.running) {
				return;
			}

			var contexts = getSelectedBulkContexts();
			if (!contexts.length) {
				showNotice(i18n.selectFields || 'Select at least one field to generate.', 'warning');
				return;
			}

			$startBtn.prop('disabled', true).text(i18n.processing || 'Processing...');
			$stopBtn.prop('disabled', false);

			// Fetch the list of image IDs first.
			$.post(admin.ajaxUrl, {
				action: 'visionati_get_images',
				nonce: admin.nonce,
				'contexts[]': contexts,
			})
				.done(function (response) {
					logServerTrace(response.data);
					if (
						response.success &&
						response.data.ids &&
						response.data.ids.length > 0
					) {
						var count = response.data.ids.length;
						var overwrite = admin.overwriteFields || [];
						var selectedContexts = getSelectedBulkContexts();
						var hasOverwrite = selectedContexts.some(function (c) { return overwrite.indexOf(c) !== -1; });
						var msg = hasOverwrite
							? (i18n.confirmBulkOverwrite || 'Process %d images? Overwrite is enabled — existing content will be replaced. Each image uses at least 1 API credit per field.')
							: (i18n.confirmBulk || 'Process %d images? Each image uses at least 1 API credit per field.');
						msg = msg.replace('%d', count);

						if (!confirm(msg)) {
							$startBtn.prop('disabled', false).text(i18n.start || 'Start');
							$stopBtn.prop('disabled', true);
							return;
						}
						startBulkProcessing(response.data.ids);
					} else {
						$startBtn
							.prop('disabled', false)
							.text(i18n.start || 'Start');
						showNotice(i18n.noImages || 'No images found to process.', 'warning');
					}
				})
				.fail(function () {
					$startBtn
						.prop('disabled', false)
						.text(i18n.start || 'Start');
					showNotice(i18n.error || 'Error');
				});
		});

		$stopBtn.on('click', function () {
			bulkState.running = false;
			$stopBtn.prop('disabled', true);
			$startBtn.prop('disabled', false).text(i18n.resume || 'Resume');
		});

		// Auto-start if pre-queued IDs exist from the Media Library bulk action.
		if (typeof visionatiBulkQueue !== 'undefined' && visionatiBulkQueue.length > 0) {
			$startBtn.prop('disabled', true).text(i18n.processing || 'Processing...');
			$stopBtn.prop('disabled', false);
			startBulkProcessing(visionatiBulkQueue);
		}
	}

	function startBulkProcessing(ids) {
		var isResume = $('.visionati-bulk-progress').is(':visible');

		bulkState.running = true;
		bulkState.ids = ids;
		bulkState.current = 0;
		bulkState.generated = isResume ? bulkState.generated : 0;
		bulkState.skipped = isResume ? bulkState.skipped : 0;
		bulkState.errors = isResume ? bulkState.errors : 0;

		var $progress = $('.visionati-bulk-progress');
		var $log = $('.visionati-bulk-log');

		$progress.show();
		$log.show();

		// Only clear log on fresh start, not resume — so previous results are preserved.
		if (!isResume) {
			$('#visionati-bulk-log-entries').empty();
		}

		updateBulkProgress();
		processNextBulkItem();
	}

	function processNextBulkItem() {
		if (!bulkState.running || bulkState.current >= bulkState.ids.length) {
			bulkFinished();
			return;
		}

		var attachmentId = bulkState.ids[bulkState.current];
		var contexts = getSelectedBulkContexts();
		if (!contexts.length) {
			contexts = ['alt_text'];
		}

		$.post(admin.ajaxUrl, {
			action: 'visionati_bulk_analyze',
			nonce: admin.nonce,
			attachment_id: attachmentId,
			'contexts[]': contexts,
		})
			.done(function (response) {
				logServerTrace(response.data);
				if (response.success) {
					if (response.data.status === 'skipped') {
						bulkState.skipped++;
						addLogEntry(response.data, response.data.message || (i18n.skipped || 'Skipped'), 'skipped');
					} else {
						bulkState.generated++;
						var msg = formatFieldNames(response.data.fields) || (i18n.generated || 'Generated.');
						addLogEntry(response.data, msg, 'generated');
					}
					updateCreditsDisplay(response.data.credits);
				} else {
					var errorMsg = response.data.message || (i18n.failed || 'Failed');
					bulkState.errors++;
					addLogEntry(response.data, errorMsg, 'failed');

					if (isCreditError(errorMsg)) {
						addCreditErrorEntry();
						bulkFinished();
						return;
					}
				}
			})
			.fail(function () {
				bulkState.errors++;
				addLogEntry({ filename: '#' + attachmentId }, i18n.failed || 'Failed', 'failed');
			})
			.always(function () {
				if (!bulkState.running) {
					return;
				}
				bulkState.current++;
				updateBulkProgress();
				processNextBulkItem();
			});
	}

	function updateBulkProgress() {
		var total = bulkState.ids.length;
		var current = bulkState.current;
		var percent = total > 0 ? Math.round((current / total) * 100) : 0;

		$('#visionati-bulk-current').text(current);
		$('#visionati-bulk-total').text(total);
		$('#visionati-bulk-percent').text(percent);
		var valueText = current + ' ' + (i18n.of || 'of') + ' ' + total
			+ ' — ' + bulkState.generated + ' ' + (i18n.generated || 'generated').toLowerCase()
			+ ', ' + bulkState.skipped + ' ' + (i18n.skipped || 'skipped').toLowerCase()
			+ ', ' + bulkState.errors + ' ' + (i18n.error || 'errors').toLowerCase();

		$('.visionati-bulk-progress .visionati-progress-bar')
			.css('width', percent + '%')
			.attr('aria-valuenow', percent)
			.attr('aria-valuetext', valueText);

		$('.visionati-summary-generated').text(bulkState.generated);
		$('.visionati-summary-skipped').text(bulkState.skipped);
		$('.visionati-summary-errors').text(bulkState.errors);
	}

	function addLogEntry(data, message, status) {
		var $log = $('#visionati-bulk-log-entries');
		if (!$log.length) {
			$log = $('#visionati-woo-bulk-log-entries');
		}
		var id = data.attachment_id || data.product_id || '';
		var name = data.filename || ('Image #' + id);

		var $entry = $('<div>').addClass('visionati-log-entry');

		// Always show a thumb slot for consistent alignment.
		if (data.thumb) {
			$entry.append(
				$('<img>').addClass('visionati-log-thumb').attr({ src: data.thumb, alt: '' })
			);
		} else {
			$entry.append(
				$('<span>').addClass('visionati-log-thumb visionati-log-thumb-placeholder')
			);
		}

		if (id) {
			$entry.append(
				$('<a>')
					.addClass('visionati-log-name')
					.attr({ href: admin.adminUrl + 'post.php?post=' + id + '&action=edit', target: '_blank', rel: 'noopener' })
					.text(name)
			);
		} else {
			$entry.append(
				$('<span>').addClass('visionati-log-name').text(name)
			);
		}

		$entry.append(
			$('<span>').addClass('visionati-log-status ' + status).text(message)
		);

		$log.append($entry);

		// Auto-scroll to bottom.
		$log.scrollTop($log[0].scrollHeight);
	}

	function addCreditErrorEntry() {
		var $log = $('#visionati-bulk-log-entries');
		if (!$log.length) {
			$log = $('#visionati-woo-bulk-log-entries');
		}
		var $entry = $(
			'<div class="visionati-log-entry">' +
				'<span class="visionati-log-status failed">' +
				'Out of credits. <a href="https://api.visionati.com/payment" target="_blank" rel="noopener">Add credits</a> to continue.' +
				'</span>' +
			'</div>'
		);

		$log.append($entry);
		$log.scrollTop($log[0].scrollHeight);
	}

	function bulkFinished() {
		bulkState.running = false;

		var $startBtn = $('#visionati-bulk-start');
		var $stopBtn = $('#visionati-bulk-stop');

		$startBtn.prop('disabled', false).text(i18n.start || 'Start');
		$stopBtn.prop('disabled', true);

		updateBulkProgress();

		$('.visionati-bulk-progress .visionati-progress-summary').trigger('focus');
	}

	// -------------------------------------------------------------------------
	// WooCommerce: Single Product Meta Box
	// -------------------------------------------------------------------------

	function initWooMetaBox() {
		$(document).on('click', '.visionati-woo-generate-btn', function (e) {
			e.preventDefault();

			var $button = $(this);
			var $status = $button.siblings('.visionati-woo-status');
			var $results = $button.closest('.visionati-woo-meta-box').find('.visionati-woo-results');
			var productId = $button.data('product-id');

			if (!productId || $button.hasClass('is-loading')) {
				return;
			}

			log('woo generate: starting', { productId: productId });
			$button.addClass('is-loading').prop('disabled', true);
			$status.text(i18n.generating || 'Generating...').addClass('loading');
			$results.hide();
			// Reset per-field state from any previous generation.
			$results.find('.visionati-field-applied').removeClass('visionati-field-applied');
			$results.find('.visionati-woo-apply-single-btn').show();
			$results.find('.visionati-woo-field-status').text('').attr('class', 'visionati-woo-field-status');

			$.post(admin.ajaxUrl, {
				action: 'visionati_woo_generate',
				nonce: admin.nonce,
				product_id: productId,
			})
				.done(function (response) {
					log('woo generate: response', response.data);
					logServerTrace(response.data);
					if (response.success) {
						var genMsg = i18n.generated || 'Generated.';
						if (response.data.credits !== undefined && response.data.credits !== null) {
							genMsg += ' (' + (i18n.creditsRemaining || '%d credits remaining').replace('%d', response.data.credits) + ')';
						}
						$status.text(genMsg).removeClass('loading').addClass('success');
						log('woo generate: preview data', {
							hasShort: !!response.data.short_description,
							shortLength: response.data.short_description ? response.data.short_description.length : 0,
							hasLong: !!response.data.long_description,
							longLength: response.data.long_description ? response.data.long_description.length : 0,
						});
						// Store preview data so Apply can send it back without re-generating.
						$results.data('preview', response.data);
						showWooPreview($results, response.data);
					} else {
						$status
							.text((i18n.error || 'Error') + ': ' + response.data.message)
							.removeClass('loading')
							.addClass('error');
					}
				})
				.fail(function () {
					$status
						.text(i18n.error || 'Error')
						.removeClass('loading')
						.addClass('error');
				})
				.always(function () {
					$button.removeClass('is-loading').prop('disabled', false);
				});
		});

		// Per-field Apply button: save just one description.
		$(document).on('click', '.visionati-woo-apply-single-btn', function (e) {
			e.preventDefault();

			var $button = $(this);
			var $previewBlock = $button.closest('.visionati-woo-preview-short, .visionati-woo-preview-long');
			var $fieldStatus = $previewBlock.find('.visionati-woo-field-status');
			var $metaBox = $button.closest('.visionati-woo-meta-box');
			var $results = $metaBox.find('.visionati-woo-results');
			var productId = $button.data('product-id');
			var field = $button.data('field');
			var preview = $results.data('preview');

			if (!preview || !productId || !field) {
				return;
			}

			var shortVal = (field === 'short') ? (preview.short_description || '') : '';
			var longVal = (field === 'long') ? (preview.long_description || '') : '';

			if (!shortVal && !longVal) {
				return;
			}

			log('woo apply single: starting', { productId: productId, field: field });
			$button.prop('disabled', true);
			$fieldStatus.text(i18n.processing || 'Processing...').attr('class', 'visionati-woo-field-status loading');

			$.post(admin.ajaxUrl, {
				action: 'visionati_woo_apply',
				nonce: admin.nonce,
				product_id: productId,
				short_description: shortVal,
				long_description: longVal,
			})
				.done(function (response) {
					log('woo apply single: response', response.data);
					logServerTrace(response.data);
					if (response.success) {
						$fieldStatus.text(i18n.complete || 'Applied.').attr('class', 'visionati-woo-field-status success');
						$button.hide();

						// Mark this field as applied so Apply to Product skips it.
						$previewBlock.addClass('visionati-field-applied');

						updateWooEditorFields(response.data);
					} else {
						$fieldStatus
							.text((i18n.error || 'Error') + ': ' + (response.data.message || ''))
							.attr('class', 'visionati-woo-field-status error');
					}
				})
				.fail(function () {
					$fieldStatus.text(i18n.error || 'Error').attr('class', 'visionati-woo-field-status error');
				})
				.always(function () {
					$button.prop('disabled', false);
				});
		});

		// Apply to Product button: save whatever hasn't been individually applied yet.
		$(document).on('click', '.visionati-woo-apply-btn', function (e) {
			e.preventDefault();

			var $button = $(this);
			var $metaBox = $button.closest('.visionati-woo-meta-box');
			var $results = $metaBox.find('.visionati-woo-results');
			var $status = $metaBox.find('.visionati-woo-status');
			var productId = $button.data('product-id');
			var preview = $results.data('preview');

			if (!preview) {
				log('woo apply: no preview data found');
				$status.text(i18n.error || 'Error').attr('class', 'visionati-woo-status error');
				return;
			}

			// Skip fields that were already individually applied.
			var shortVal = $results.find('.visionati-woo-preview-short').hasClass('visionati-field-applied')
				? '' : (preview.short_description || '');
			var longVal = $results.find('.visionati-woo-preview-long').hasClass('visionati-field-applied')
				? '' : (preview.long_description || '');

			if (!shortVal && !longVal) {
				$status.text(i18n.complete || 'Complete.').attr('class', 'visionati-woo-status success');
				return;
			}

			log('woo apply: starting', {
				productId: productId,
				hasShort: !!shortVal,
				shortLength: shortVal.length,
				hasLong: !!longVal,
				longLength: longVal.length,
			});

			$button.prop('disabled', true);
			$status.text(i18n.processing || 'Processing...').attr('class', 'visionati-woo-status loading');

			$.post(admin.ajaxUrl, {
				action: 'visionati_woo_apply',
				nonce: admin.nonce,
				product_id: productId,
				short_description: shortVal,
				long_description: longVal,
			})
				.done(function (response) {
					log('woo apply: response', response.data);
					logServerTrace(response.data);
					if (response.success) {
						$status.text(i18n.complete || 'Complete.').attr('class', 'visionati-woo-status success');

						// Update the post editor fields if available.
						updateWooEditorFields(response.data);
					} else {
						$status
							.text((i18n.error || 'Error') + ': ' + response.data.message)
							.attr('class', 'visionati-woo-status error');
					}
				})
				.fail(function () {
					$status.text(i18n.error || 'Error').attr('class', 'visionati-woo-status error');
				})
				.always(function () {
					$button.prop('disabled', false);
				});
		});

		// Discard button.
		$(document).on('click', '.visionati-woo-discard-btn', function (e) {
			e.preventDefault();
			var $results = $(this).closest('.visionati-woo-results');
			$results.removeData('preview');
			$results.hide();
			// Reset per-field state.
			$results.find('.visionati-field-applied').removeClass('visionati-field-applied');
			$results.find('.visionati-woo-apply-single-btn').show();
			$results.find('.visionati-woo-field-status').text('').attr('class', 'visionati-woo-field-status');
			$results.closest('.visionati-woo-meta-box').find('.visionati-woo-status').text('').attr('class', 'visionati-woo-status');
		});
	}

	function showWooPreview($results, data) {
		if (data.short_description) {
			$results.find('#visionati-woo-short-preview').text(data.short_description);
			$results.find('.visionati-woo-preview-short').show();
		} else {
			$results.find('.visionati-woo-preview-short').hide();
		}

		if (data.long_description) {
			$results.find('#visionati-woo-long-preview').html(data.long_description);
			$results.find('.visionati-woo-preview-long').show();
		} else {
			$results.find('.visionati-woo-preview-long').hide();
		}

		$results.show();
	}

	/**
	 * Attempt to update the WooCommerce product editor fields after applying descriptions.
	 */
	function updateWooEditorFields(data) {
		var updated = false;

		log('updateWooEditorFields: starting', {
			hasShort: !!data.short_description,
			hasLong: !!data.long_description,
		});

		// Short description: WooCommerce wraps #excerpt in its own TinyMCE
		// instance. Update both the visual editor and the raw textarea so
		// the value is correct regardless of which tab is active.
		if (data.short_description) {
			if (typeof tinymce !== 'undefined') {
				var excerptEditor = tinymce.get('excerpt');
				if (excerptEditor && !excerptEditor.isHidden()) {
					excerptEditor.setContent(data.short_description);
					updated = true;
					log('updateWooEditorFields: TinyMCE excerpt set');
				} else {
					log('updateWooEditorFields: TinyMCE excerpt not found or hidden', {
						exists: !!excerptEditor,
						hidden: excerptEditor ? excerptEditor.isHidden() : 'N/A',
					});
				}
			}
			var $excerpt = $('#excerpt');
			if ($excerpt.length) {
				$excerpt.val(data.short_description);
				updated = true;
				log('updateWooEditorFields: #excerpt textarea set');
			} else {
				log('updateWooEditorFields: #excerpt element NOT found');
			}
		}

		// Long description: TinyMCE or the raw textarea.
		if (data.long_description) {
			if (typeof tinymce !== 'undefined') {
				var editor = tinymce.get('content');
				if (editor && !editor.isHidden()) {
					editor.setContent(data.long_description);
					updated = true;
					log('updateWooEditorFields: TinyMCE content set');
				} else if ($('#content').length) {
					$('#content').val(data.long_description);
					updated = true;
					log('updateWooEditorFields: #content textarea set (TinyMCE hidden)');
				} else {
					log('updateWooEditorFields: #content not found, TinyMCE exists but hidden');
				}
			} else if ($('#content').length) {
				$('#content').val(data.long_description);
				updated = true;
				log('updateWooEditorFields: #content textarea set (no TinyMCE)');
			} else {
				log('updateWooEditorFields: #content not found, no TinyMCE');
			}
		}

		log('updateWooEditorFields: result', { updated: updated });

		if (updated) {
			// Classic editor: reset dirty-state. The product was already saved
			// server-side via $product->save(), so these editor field updates
			// are cosmetic. Without this, WP shows "You have unsaved changes."
			if (typeof wp !== 'undefined' && wp.autosave && wp.autosave.server
				&& typeof wp.autosave.server.tempBlockSave === 'function') {
				wp.autosave.server.tempBlockSave();
			}
			$(window).off('beforeunload.edit-post');
		} else if (data.short_description || data.long_description) {
			// Block editor or new WooCommerce product editor: classic fields
			// not found. Data is already saved server-side via $product->save(),
			// so reload to display the saved descriptions.
			log('updateWooEditorFields: no classic fields found, reloading page');
			window.location.reload();
		}
	}

	// -------------------------------------------------------------------------
	// WooCommerce: Bulk Product Descriptions
	// -------------------------------------------------------------------------

	var wooBulkState = {
		running: false,
		ids: [],
		current: 0,
		generated: 0,
		skipped: 0,
		errors: 0,
	};

	function getSelectedWooStatuses() {
		var statuses = [];
		$('input[name="visionati_woo_bulk_status"]:checked').each(function () {
			statuses.push($(this).val());
		});
		return statuses;
	}

	function refreshWooStats() {
		var statuses = getSelectedWooStatuses();
		var $stats = $('#visionati-woo-bulk-stats');

		if (!$stats.length) {
			return;
		}

		if (!statuses.length) {
			$stats.text(i18n.selectStatuses || 'Select at least one product status.');
			return;
		}

		$.post(admin.ajaxUrl, {
			action: 'visionati_woo_get_stats',
			nonce: admin.nonce,
			'statuses[]': statuses,
		}).done(function (response) {
			logServerTrace(response.data);
			if (response.success) {
				var d = response.data;
				var msg = (i18n.wooStats || '%1$d of %2$d products with images are missing descriptions.')
					.replace('%1$d', d.missing)
					.replace('%2$d', d.total);
				$stats.text(msg);
			}
		});
	}

	function initWooBulkGenerate() {
		var $startBtn = $('#visionati-woo-bulk-start');
		var $stopBtn = $('#visionati-woo-bulk-stop');

		if (!$startBtn.length) {
			return;
		}

		// Refresh stats when status checkboxes change.
		$(document).on('change', 'input[name="visionati_woo_bulk_status"]', refreshWooStats);

		$startBtn.on('click', function () {
			if (wooBulkState.running) {
				return;
			}

			var statuses = getSelectedWooStatuses();

			if (!statuses.length) {
				showNotice(i18n.selectStatuses || 'Select at least one product status.', 'warning');
				return;
			}

			$startBtn.prop('disabled', true).text(i18n.processing || 'Processing...');
			$stopBtn.prop('disabled', false);

			$.post(admin.ajaxUrl, {
				action: 'visionati_woo_get_products',
				nonce: admin.nonce,
				'statuses[]': statuses,
			})
				.done(function (response) {
					logServerTrace(response.data);
					if (
						response.success &&
						response.data.ids &&
						response.data.ids.length > 0
					) {
						var count = response.data.ids.length;
						var overwrite = admin.overwriteFields || [];
						var hasOverwrite = overwrite.indexOf('description') !== -1;
						var msg = hasOverwrite
							? (i18n.confirmWooBulkOverwrite || 'Process %d products? Overwrite is enabled — existing descriptions will be replaced. Each product uses at least 2 API credits.')
							: (i18n.confirmWooBulk || 'Process %d products? Each product uses at least 2 API credits (short + long description).');
						msg = msg.replace('%d', count);

						if (!confirm(msg)) {
							$startBtn.prop('disabled', false).text(i18n.start || 'Start');
							$stopBtn.prop('disabled', true);
							return;
						}
						startWooBulkProcessing(response.data.ids);
					} else {
						$startBtn
							.prop('disabled', false)
							.text(i18n.start || 'Start');
						showNotice(i18n.noProducts || 'No products found to process.', 'warning');
					}
				})
				.fail(function () {
					$startBtn
						.prop('disabled', false)
						.text(i18n.start || 'Start');
					showNotice(i18n.error || 'Error');
				});
		});

		$stopBtn.on('click', function () {
			wooBulkState.running = false;
			$stopBtn.prop('disabled', true);
			$startBtn.prop('disabled', false).text(i18n.resume || 'Resume');
		});

		// Auto-start if pre-queued IDs exist from the Products bulk action.
		if (typeof visionatiWooBulkQueue !== 'undefined' && visionatiWooBulkQueue.length > 0) {
			$startBtn.prop('disabled', true).text(i18n.processing || 'Processing...');
			$stopBtn.prop('disabled', false);
			startWooBulkProcessing(visionatiWooBulkQueue);
		}
	}

	function startWooBulkProcessing(ids) {
		var isResume = $('.visionati-woo-bulk-progress').is(':visible');

		wooBulkState.running = true;
		wooBulkState.ids = ids;
		wooBulkState.current = 0;
		wooBulkState.generated = isResume ? wooBulkState.generated : 0;
		wooBulkState.skipped = isResume ? wooBulkState.skipped : 0;
		wooBulkState.errors = isResume ? wooBulkState.errors : 0;

		$('.visionati-woo-bulk-progress').show();
		$('.visionati-woo-bulk-log').show();

		if (!isResume) {
			$('#visionati-woo-bulk-log-entries').empty();
		}

		updateWooBulkProgress();
		processNextWooBulkItem();
	}

	function processNextWooBulkItem() {
		if (!wooBulkState.running || wooBulkState.current >= wooBulkState.ids.length) {
			wooBulkFinished();
			return;
		}

		var productId = wooBulkState.ids[wooBulkState.current];

		$.post(admin.ajaxUrl, {
			action: 'visionati_woo_bulk_generate',
			nonce: admin.nonce,
			product_id: productId,
		})
			.done(function (response) {
				logServerTrace(response.data);
				if (response.success) {
					if (response.data.status === 'skipped') {
						wooBulkState.skipped++;
						addLogEntry(response.data, response.data.message || (i18n.skipped || 'Skipped'), 'skipped');
					} else {
						wooBulkState.generated++;
						addLogEntry(response.data, i18n.generated || 'Generated.', 'generated');
					}
					updateCreditsDisplay(response.data.credits);
				} else {
					var errorMsg = response.data.message || (i18n.failed || 'Failed');
					wooBulkState.errors++;
					addLogEntry(response.data, errorMsg, 'failed');

					if (isCreditError(errorMsg)) {
						addCreditErrorEntry();
						wooBulkFinished();
						return;
					}
				}
			})
			.fail(function () {
				wooBulkState.errors++;
				addLogEntry({ filename: '#' + productId }, i18n.failed || 'Failed', 'failed');
			})
			.always(function () {
				if (!wooBulkState.running) {
					return;
				}
				wooBulkState.current++;
				updateWooBulkProgress();
				processNextWooBulkItem();
			});
	}

	function updateWooBulkProgress() {
		var total = wooBulkState.ids.length;
		var current = wooBulkState.current;
		var percent = total > 0 ? Math.round((current / total) * 100) : 0;

		$('.visionati-woo-bulk-progress .visionati-bulk-current').text(current);
		$('.visionati-woo-bulk-progress .visionati-bulk-total').text(total);
		$('.visionati-woo-bulk-progress .visionati-bulk-percent').text(percent);
		var valueText = current + ' ' + (i18n.of || 'of') + ' ' + total
			+ ' — ' + wooBulkState.generated + ' ' + (i18n.generated || 'generated').toLowerCase()
			+ ', ' + wooBulkState.skipped + ' ' + (i18n.skipped || 'skipped').toLowerCase()
			+ ', ' + wooBulkState.errors + ' ' + (i18n.error || 'errors').toLowerCase();

		$('.visionati-woo-bulk-progress .visionati-progress-bar')
			.css('width', percent + '%')
			.attr('aria-valuenow', percent)
			.attr('aria-valuetext', valueText);

		$('.visionati-woo-bulk-progress .visionati-summary-generated').text(wooBulkState.generated);
		$('.visionati-woo-bulk-progress .visionati-summary-skipped').text(wooBulkState.skipped);
		$('.visionati-woo-bulk-progress .visionati-summary-errors').text(wooBulkState.errors);
	}

	function wooBulkFinished() {
		wooBulkState.running = false;

		var $startBtn = $('#visionati-woo-bulk-start');
		var $stopBtn = $('#visionati-woo-bulk-stop');

		$startBtn.prop('disabled', false).text(i18n.start || 'Start');
		$stopBtn.prop('disabled', true);

		updateWooBulkProgress();

		$('.visionati-woo-bulk-progress .visionati-progress-summary').trigger('focus');
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	$(function () {
		log('init: starting', { debug: isDebug, overwriteFields: admin.overwriteFields });
		initVerifyKey();
		initMediaButtons();
		initBulkGenerate();
		initWooMetaBox();
		initWooBulkGenerate();
		log('init: complete');
	});
})(jQuery);
