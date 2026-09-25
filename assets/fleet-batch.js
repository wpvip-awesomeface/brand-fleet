/* global brandFleetBatch */
(function () {
	'use strict';
	const current = document.getElementById('brand-fleet-current-batch');
	const changed = document.getElementById('brand-fleet-batch-changed');
	const form = document.querySelector('input[name=task][value=bulk]')?.form;
	if (form && current && changed) {
		const invalidate = event => {
			if (event.target.matches('input[type=search]')) return;
			current.hidden = true; changed.hidden = false;
		};
		form.addEventListener('change', invalidate);
		form.addEventListener('input', invalidate);
	}
	const button = document.getElementById('brand-fleet-batch-run');
	const status = document.getElementById('brand-fleet-batch-status');
	if (!button || !status) return;
	button.addEventListener('click', async function () {
		button.disabled = true;
		let confirm = brandFleetBatch.phase === 'ready';
		try {
			do {
				const data = new URLSearchParams({action:'brand_fleet_batch', nonce:brandFleetBatch.nonce, id:brandFleetBatch.id, cursor:String(brandFleetBatch.cursor)});
				if (confirm) data.set('confirm','1');
				const response = await fetch(brandFleetBatch.url, {method:'POST', credentials:'same-origin', body:data});
				const result = await response.json();
				if (!result.success) throw new Error(result.data || 'Batch failed. Reload to resume.');
				Object.assign(brandFleetBatch, result.data);
				confirm = false;
				status.textContent = result.data.phase + ' · ' + result.data.cursor + '/' + result.data.total;
			} while (['preview','apply'].includes(brandFleetBatch.phase));
			window.location.reload();
		} catch (error) { status.textContent = error.message + ' Reload this page to resume.'; }
	});
}());
