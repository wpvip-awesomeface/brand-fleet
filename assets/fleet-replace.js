/* global brandFleetReplace */
(function () {
	'use strict';
	if (typeof brandFleetReplace === 'undefined') return;
	const selected = document.getElementById('brand-fleet-selected');
	// Each checkbox saves right away, so leaving pages out survives paging through results.
	document.querySelectorAll('.brand-fleet-include').forEach(box => {
		box.addEventListener('change', async () => {
			box.disabled = true;
			try {
				const body = new URLSearchParams({action: 'brand_fleet_replace_exclude', nonce: brandFleetReplace.nonce, id: brandFleetReplace.id, row: box.dataset.row, post: box.dataset.post, excluded: box.checked ? '' : '1'});
				const result = await (await fetch(brandFleetReplace.url, {method: 'POST', credentials: 'same-origin', body})).json();
				if (!result.success) throw new Error(result.data || 'Could not save.');
				if (selected) selected.textContent = (Number(selected.dataset.total) - result.data.excluded) + ' selected.';
				const cell = box.closest('tr').lastElementChild;
				cell.textContent = box.checked ? '' : 'Left out';
			} catch (error) {
				box.checked = !box.checked;
				window.alert(error.message);
			} finally { box.disabled = false; }
		});
	});
}());
