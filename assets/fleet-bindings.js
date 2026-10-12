/* global wp, brandFleetBindings */
(function () {
	'use strict';
	if (!wp.blocks || !wp.blocks.registerBlockBindingsSource || typeof brandFleetBindings === 'undefined') return;
	const fields = brandFleetBindings.fields || [];
	const find = key => fields.find(field => field.key === key);
	// The editor previews this site's value; the front end always renders it on the server.
	wp.blocks.registerBlockBindingsSource({
		name: 'brand-fleet/variable',
		label: 'Brand Fleet variable',
		getValues({bindings}) {
			const values = {};
			Object.entries(bindings).forEach(([attribute, binding]) => {
				const field = find(binding.args?.key);
				values[attribute] = field ? (field.value || field.label) : binding.args?.key;
			});
			return values;
		},
		getFieldsList() {
			return fields.map(field => ({label: field.label, type: 'string', args: {key: field.key}}));
		},
	});
}());
