/* global window, document, wp */
/**
 * PostPress AI — Featured Image Picker (ES5-safe)
 *
 * Wires the WP media frame to #ppa-thumbnail-id.
 * Depends on: wp_enqueue_media() (called by enqueue.php), ppa-admin.
 *
 * DOM contract:
 *   #ppa-thumbnail-id     — hidden input that holds the attachment ID
 *   #ppa-thumbnail-btn    — "Set Featured Image" button
 *   #ppa-thumbnail-remove — "Remove" button
 *   #ppa-thumbnail-preview — wrapper div shown when an image is selected
 *   #ppa-thumbnail-img    — <img> preview element
 */
(function (window, document) {
  'use strict';

  function init() {
    var idInput   = document.getElementById('ppa-thumbnail-id');
    var btn       = document.getElementById('ppa-thumbnail-select');
    var removeBtn = document.getElementById('ppa-thumbnail-remove');
    var preview   = document.getElementById('ppa-thumbnail-preview');
    var img       = document.getElementById('ppa-thumbnail-img');

    if (!idInput || !btn) {
      try { console.warn('PPA thumbnail: required elements missing — idInput=' + !!idInput + ', btn=' + !!btn); } catch(e) {}
      return;
    }

    var frame;

    function clearThumbnail() {
      idInput.value = '';
      if (img)     { img.src = ''; }
      if (preview) { preview.style.display = 'none'; }
      if (removeBtn) { removeBtn.style.display = 'none'; }
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();

      if (!window.wp || !window.wp.media) {
        window.alert('WordPress media library is not available.');
        return;
      }

      if (frame) {
        frame.open();
        return;
      }

      frame = window.wp.media({
        title:    'Select Featured Image',
        button:   { text: 'Set Featured Image' },
        multiple: false,
        library:  { type: 'image' }
      });

      frame.on('select', function () {
        var selection = frame.state().get('selection');
        if (!selection) { return; }
        var model = selection.first();
        // Read from the Backbone model directly (before toJSON strips the id)
        var id  = model.get('id');
        var sizes = model.get('sizes');
        var url = (sizes && sizes.thumbnail)
          ? sizes.thumbnail.url
          : model.get('url') || '';

        idInput.value = id ? String(id) : '';
        try { console.info('PPA thumbnail selected: id=' + id + ', url=' + url + ', input_set_to="' + idInput.value + '"'); } catch(e) {}
        if (img && url)  { img.src = url; }
        if (preview)     { preview.style.display = id ? '' : 'none'; }
        if (removeBtn)   { removeBtn.style.display = id ? '' : 'none'; }
      });

      frame.open();
    });

    if (removeBtn) {
      removeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        clearThumbnail();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})(window, document);
