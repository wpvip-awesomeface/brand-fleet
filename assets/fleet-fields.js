/* global jQuery, wp */
(function ($) {
	'use strict';
	// Picker and media changes fire a normal input event so other scripts (override checkboxes, batch invalidation) notice them.
	const changed = input => input.dispatchEvent(new Event('input', {bubbles: true}));

	function colors() {
		if (!$.fn.wpColorPicker) return;
		document.querySelectorAll('.brand-fleet-color').forEach(input => {
			$(input).wpColorPicker({
				change: (event, ui) => { input.value = ui.color.toString(); changed(input); },
				clear: () => setTimeout(() => changed(input)),
			});
		});
	}

	function images() {
		document.querySelectorAll('.brand-fleet-image').forEach(box => {
			const input = box.querySelector('input'), preview = box.querySelector('.brand-fleet-image-preview');
			const choose = box.querySelector('.brand-fleet-image-choose'), clear = box.querySelector('.brand-fleet-image-clear');
			const show = () => {
				const url = input.value.trim();
				preview.hidden = !url; clear.hidden = !url;
				if (url && preview.getAttribute('src') !== url) preview.src = url;
			};
			let frame;
			input.addEventListener('input', show);
			preview.addEventListener('error', () => { preview.hidden = true; });
			clear.addEventListener('click', () => { input.value = ''; show(); changed(input); });
			if (choose) choose.addEventListener('click', () => {
				if (!window.wp || !wp.media) return;
				frame = frame || wp.media({title: 'Choose an image', library: {type: 'image'}, button: {text: 'Use this image'}, multiple: false});
				frame.off('select').on('select', () => {
					input.value = frame.state().get('selection').first().toJSON().url;
					show(); changed(input);
				});
				frame.open();
			});
			show();
		});
	}

	// Show the input that matches the chosen type; disabled inputs are never submitted.
	function typed() {
		document.querySelectorAll('.brand-fleet-typed').forEach(group => {
			const control = group.closest('form')?.querySelector('select[name="' + group.dataset.control + '"]');
			if (!control) return;
			const sync = () => {
				const type = control.selectedOptions[0]?.dataset.type || control.value;
				group.querySelectorAll(':scope > [data-type]').forEach(panel => {
					const on = panel.dataset.type === type;
					panel.hidden = !on;
					panel.querySelectorAll('input, textarea').forEach(input => { input.disabled = !on; });
				});
			};
			control.addEventListener('change', sync);
			sync();
		});
	}

	// Editing a site value turns on its override, so a typed value is never silently ignored.
	function overrides() {
		document.querySelectorAll('.brand-fleet-override').forEach(row => {
			const box = row.querySelector('input[name="' + row.dataset.override + '"]');
			if (!box) return;
			row.addEventListener('input', event => { if (event.target !== box) box.checked = true; });
		});
	}

	$(() => { typed(); colors(); images(); overrides(); });
}(jQuery));
