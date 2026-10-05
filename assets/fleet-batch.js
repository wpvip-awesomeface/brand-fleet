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
	const status = document.getElementById('brand-fleet-batch-status');
	if (!status || typeof brandFleetBatch === 'undefined') return;
	const run = document.getElementById('brand-fleet-batch-run');
	const cancel = document.getElementById('brand-fleet-batch-cancel');
	const active = phase => ['preview', 'apply'].includes(phase);
	const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
	const send = async extra => {
		const data = new URLSearchParams({action: 'brand_fleet_batch', nonce: brandFleetBatch.nonce, id: brandFleetBatch.id, cursor: String(brandFleetBatch.cursor), ...extra});
		const response = await fetch(brandFleetBatch.url, {method: 'POST', credentials: 'same-origin', body: data});
		const result = await response.json();
		if (!result.success) throw new Error(result.data || 'Batch request failed.');
		Object.assign(brandFleetBatch, result.data);
		status.textContent = (brandFleetBatch.labels[result.data.phase] || result.data.phase) + ' · ' + result.data.cursor + '/' + result.data.total + ' sites';
		return result.data;
	};
	// The server finishes the batch on its own; stepping from an open page only speeds it up.
	const follow = async extra => {
		try {
			let data = await send(extra);
			while (active(data.phase)) {
				if (data.busy) await wait(3000);
				data = await send({});
			}
			window.location.reload();
		} catch (error) { status.textContent = error.message + ' The batch keeps running in the background; reload to check progress.'; }
	};
	if (run) run.addEventListener('click', () => { run.disabled = true; follow({confirm: '1'}); });
	if (cancel) cancel.addEventListener('click', async () => {
		if (!window.confirm('Stop this batch? Sites already updated keep their new values.')) return;
		cancel.disabled = true;
		try { await send({cancel: '1'}); window.location.reload(); } catch (error) { status.textContent = error.message; cancel.disabled = false; }
	});
	if (active(brandFleetBatch.phase)) follow({});
}());
