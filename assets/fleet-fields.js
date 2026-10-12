/* global jQuery, wp */
(function ($) {
	'use strict';
	// Picker and media changes fire a normal input event so other scripts (override checkboxes, batch invalidation) notice them.
	const changed = input => input.dispatchEvent(new Event('input', {bubbles: true}));
	// Current value of a named control in a form: a select, a checked radio, or a checkbox.
	const valueOf = (form, name) => {
		const fields = Array.from(form.querySelectorAll('[name="' + name + '"]')).filter(field => !field.disabled || field.tagName === 'SELECT');
		const field = fields.find(f => f.type === 'radio' ? f.checked : true);
		if (!field) return '';
		if (field.type === 'checkbox') return field.checked ? field.value : '';
		return field.value;
	};

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

	// Show the panel that matches the control's value; disabled inputs are never submitted.
	function typed() {
		document.querySelectorAll('.brand-fleet-typed').forEach(group => {
			const form = group.closest('form');
			const control = form?.querySelector('select[name="' + group.dataset.control + '"]');
			if (!control) return;
			const sync = () => {
				group.querySelectorAll(':scope > [data-panel]').forEach(panel => {
					const on = panel.dataset.panel === control.value;
					panel.hidden = !on;
					panel.querySelectorAll('input, textarea, select').forEach(input => { input.disabled = !on; });
				});
			};
			control.addEventListener('change', sync);
			sync();
		});
	}

	// data-show-when="name=a,b" or "name!=a": show a block only when another control matches.
	function conditions() {
		document.querySelectorAll('[data-show-when]').forEach(block => {
			const form = block.closest('form');
			const [, name, not, list] = block.dataset.showWhen.match(/^([\w\[\]-]+)(!?)=(.*)$/) || [];
			if (!form || !name) return;
			const values = list.split(',');
			const sync = () => {
				const match = values.includes(valueOf(form, name));
				const on = not ? !match : match;
				block.hidden = !on;
				// Typed panels manage their own inputs; everything else in a hidden block is not submitted.
				block.querySelectorAll('input, textarea, select').forEach(input => { if (!input.closest('.brand-fleet-typed')) input.disabled = !on; });
			};
			form.addEventListener('change', event => { if (event.target.name === name) sync(); });
			sync();
		});
	}

	// Dropdown and yes / no defaults follow the choices typed above them.
	function choices() {
		const form = document.querySelector('.brand-fleet-definition-form')?.closest('form');
		if (!form) return;
		form.querySelectorAll('textarea[name=options]').forEach(area => {
			const panel = area.closest('[data-show-when]')?.dataset.showWhen.split('=')[1];
			const select = form.querySelector('[data-panel="' + panel + '"] select');
			if (!select) return;
			area.addEventListener('input', () => {
				const lines = area.value.split('\n').map(line => line.trim()).filter(Boolean);
				const current = select.value;
				const entries = panel === 'toggle' ? [['1', lines[0] || 'Yes'], ['0', lines[1] || 'No']] : lines.map(line => [line, line]);
				select.replaceChildren(new Option('— None —', ''), ...entries.map(([value, text]) => new Option(text, value, false, value === current)));
			});
		});
	}

	// New variables: suggest a token key from the name until someone edits the key.
	function keys() {
		document.querySelectorAll('input[data-key-from]').forEach(key => {
			const source = key.form.querySelector('[name="' + key.dataset.keyFrom + '"]');
			let touched = false;
			key.addEventListener('input', () => { touched = true; });
			source?.addEventListener('input', () => {
				if (touched) return;
				key.value = source.value.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '_').replace(/^[^a-z]+|_+$/g, '').slice(0, 48);
			});
		});
		const type = document.querySelector('.brand-fleet-definition-form select[name=type]');
		const hint = document.querySelector('.brand-fleet-type-hint');
		if (type && hint) type.addEventListener('change', () => { hint.textContent = type.selectedOptions[0]?.dataset.hint || ''; });
	}

	function copies() {
		document.addEventListener('click', event => {
			const button = event.target.closest('.brand-fleet-copy');
			if (!button || !navigator.clipboard) return;
			navigator.clipboard.writeText(button.dataset.copy).then(() => {
				button.textContent = 'Copied';
				setTimeout(() => { button.textContent = 'Copy'; }, 1500);
			});
		});
	}

	// Editing a site value turns on its override, so a typed value is never silently ignored.
	function overrides() {
		document.querySelectorAll('.brand-fleet-override').forEach(row => {
			const box = row.querySelector('input[name="' + row.dataset.override + '"]');
			if (!box) return;
			row.addEventListener('input', event => { if (event.target !== box) box.checked = true; });
			row.addEventListener('change', event => { if (event.target.tagName === 'SELECT') box.checked = true; });
		});
	}

	$(() => { typed(); conditions(); choices(); keys(); colors(); images(); overrides(); copies(); });
}(jQuery));
