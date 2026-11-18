/*
 * scale-expand.js - "Scale expanded images to screen height" option.
 *
 * inline-expanding.js gives each expanded image the class "full-image".
 * When this option is on we add a body class; CSS then caps full-image
 * height to the viewport. Applies live to already-expanded images too.
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/inline-expanding.js';
 *   $config['additional_javascript'][] = 'js/scale-expand.js';
 */

$(document).ready(function(){
	var KEY = 'scale_expanded_images';

	var apply = function(){
		$(document.body).toggleClass('scale-expanded', localStorage[KEY] !== 'false');
	};

	apply();

	if (window.Options && Options.get_tab('general')) {
		Options.extend_tab('general',
			"<label id='scale-expanded'><input type='checkbox' /> " +
			_('Scale expanded images to screen height') + "</label>");
		var cb = $('#scale-expanded > input');
		cb.prop('checked', localStorage[KEY] !== 'false');
		cb.on('change', function(){
			localStorage[KEY] = this.checked ? 'true' : 'false';
			apply();
		});
	}
});
