/* global brandFleetOnboarding */
(function () {
	'use strict';
	const fields = Array.from(document.querySelectorAll('.brand-fleet-default-field'));
	const enroll = document.querySelector('[name=brand_fleet_enroll]');
	const sourcePicker = document.querySelector('[data-name=brand_fleet_source_id]');
	const status = document.getElementById('brand-fleet-default-status');
	let generation = 0;
	function refresh(field) {
		const input = field.querySelector('textarea'), override = field.querySelector('input');
		input.placeholder = field.dataset.default || 'No inherited default';
		input.required = enroll.checked && field.dataset.required === '1' && (field.dataset.explicit === '1' || !field.dataset.default.trim() || override.checked);
		field.querySelector('.brand-fleet-default-hint').textContent = field.dataset.explicit === '1' ? 'Enter a site-specific value (required).' : (override.checked ? 'Uses your site-specific value.' : (field.dataset.default ? 'Inherits the default shown above. Leave empty to keep inheritance.' : 'No inherited default set.' + (field.dataset.required === '1' ? ' Enter a value.' : ' Optional.')));
	}
	fields.forEach(field => {
		const input = field.querySelector('textarea'), override = field.querySelector('input');
		input.addEventListener('input', () => { override.checked = input.value !== ''; refresh(field); });
		override.addEventListener('change', () => { if (!override.checked) input.value = ''; refresh(field); });
		refresh(field);
	});
	enroll.addEventListener('change', () => fields.forEach(refresh));
	sourcePicker.addEventListener('change', async () => {
		const mine = ++generation, source = sourcePicker.querySelector('input:checked')?.value || '0';
		status.textContent = 'Loading inherited defaults…';
		try {
			const response = await fetch(brandFleetOnboarding.url, {method:'POST', credentials:'same-origin', body:new URLSearchParams({action:'brand_fleet_site_defaults', nonce:brandFleetOnboarding.nonce, source})});
			const result = await response.json();
			if (mine !== generation) return;
			if (!result.success) throw new Error(result.data || 'Could not load defaults.');
			fields.forEach(field => { field.dataset.default = result.data.values[field.dataset.key] || ''; refresh(field); });
			status.textContent = source === '0' ? 'Showing network defaults.' : 'Showing defaults inherited from the selected main data site.';
		} catch (error) {
			if (mine !== generation) return;
			fields.forEach(field => { field.dataset.default = ''; refresh(field); });
			status.textContent = error.message + ' Defaults could not be previewed; check the main data source.';
		}
	});
}());
