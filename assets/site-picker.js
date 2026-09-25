/* global brandFleetSitePicker */
(function () {
	'use strict';
	// Share fetched pages across pickers; each request reads at most 25 site names.
	const pages = new Map();
	function page(offset) {
		if (!pages.has(offset)) {
			pages.set(offset, fetch(brandFleetSitePicker.url, {method:'POST', credentials:'same-origin', body:new URLSearchParams({action:'brand_fleet_site_picker', nonce:brandFleetSitePicker.nonce, offset:String(offset)})})
				.then(r => r.json()).then(r => { if (!r.success) throw new Error('Could not load sites. Try again.'); return r.data; })
				.catch(e => { pages.delete(offset); throw e; }));
		}
		return pages.get(offset);
	}
	document.querySelectorAll('.brand-fleet-site-picker').forEach(root => {
		const selected = root.querySelector('.brand-fleet-selected'), results = root.querySelector('.brand-fleet-site-results');
		const search = root.querySelector('input[type=search]'), more = root.querySelector('.brand-fleet-site-more'), status = root.querySelector('[role=status]');
		let offset = 0, generation = 0, timer, busy = false;
		function add(item) {
			if (Array.from(selected.querySelectorAll('input')).some(i => i.checked && i.value === String(item.id))) return;
			if (root.dataset.multiple !== '1') selected.replaceChildren();
			const label = document.createElement('label'), input = document.createElement('input');
			input.type = 'checkbox'; input.checked = true; input.value = String(item.id);
			input.name = root.dataset.name + (root.dataset.multiple === '1' ? '[]' : '');
			label.append(input, document.createTextNode(' ' + item.label)); selected.append(label); input.dispatchEvent(new Event('change', {bubbles:true}));
			status.textContent = 'Selected ' + item.label;
		}
		selected.addEventListener('change', e => { if (!e.target.checked) e.target.closest('label').remove(); });
		async function load(reset = false) {
			if (busy && !reset) return;
			const mine = reset ? ++generation : generation;
			if (reset) { offset = 0; results.replaceChildren(); }
			busy = true; more.disabled = true; status.textContent = 'Loading sites…';
			const query = search.value.trim().toLocaleLowerCase(); let matches = 0, hasMore = true;
			try {
				do {
					const data = await page(offset);
					if (mine !== generation) return;
					offset = data.offset; hasMore = data.more;
					data.items.forEach(item => {
						if (String(item.id) === root.dataset.exclude || !item.label.toLocaleLowerCase().includes(query)) return;
						const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = item.label;
						button.addEventListener('click', () => add(item)); results.append(button); matches++;
					});
				} while (hasMore && matches < 10);
				more.hidden = !hasMore;
				status.textContent = results.children.length ? results.children.length + ' sites shown.' : 'No matching sites.';
			} catch (e) { if (mine === generation) { status.textContent = e.message; more.hidden = false; } }
			finally { if (mine === generation) { busy = false; more.disabled = false; } }
		}
		root.querySelector('details').addEventListener('toggle', e => { if (e.target.open && !offset) load(); });
		search.addEventListener('input', () => { ++generation; busy = false; clearTimeout(timer); timer = setTimeout(() => load(true), 250); });
		more.addEventListener('click', () => load());
	});
}());
