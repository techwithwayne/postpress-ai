(function($) {

  function cleanUrl(url) {
    return String(url || '').replace(/^https?:\/\//, '').replace(/\/$/, '');
  }

  function normalizeSite(site) {
    site = site || {};

    return {
      site_id: site.site_id || site.id || 'current',
      name: site.name || '',
      url: cleanUrl(site.url || ''),
      link: site.link || site.url || ''
    };
  }

  function siteFromOption($option, fallback) {
    if (!$option || !$option.length) {
      return normalizeSite(fallback);
    }

    return normalizeSite({
      site_id: $option.val() || 'current',
      name: $option.attr('data-site-name') || '',
      url: $option.attr('data-site-url') || '',
      link: $option.attr('data-site-link') || ''
    });
  }

  function updateDestinationDisplay(site) {
    site = normalizeSite(site);

    var $link = $('#ppa-target-site-link');
    var $url = $('#ppa-target-site-url');

    if (!$link.length || !$url.length) {
      return;
    }

    var siteName = site.name || site.url || 'This site';
    var siteUrl = cleanUrl(site.url || '');
    var siteLink = site.link || '';

    $link.text(siteName);

    if (siteLink) {
      $link
        .attr('href', siteLink)
        .attr('target', '_blank')
        .attr('rel', 'noopener noreferrer')
        .removeClass('is-static');
    } else {
      $link
        .removeAttr('href')
        .removeAttr('target')
        .removeAttr('rel')
        .addClass('is-static');
    }

    if (siteUrl) {
      $url.text(siteUrl).show();
    } else {
      $url.text('').hide();
    }
  }

  function loadSites() {
    if (!window.postpressAi || !window.postpressAi.restBase || !window.postpressAi.nonce) {
      return $.Deferred().reject().promise();
    }

    return $.ajax({
      url: window.postpressAi.restBase + 'postpress-ai/v1/sites',
      method: 'GET',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', window.postpressAi.nonce);
      }
    });
  }

  function saveRemoteDraft(payload, targetSiteId) {
    if (!window.postpressAi || !window.postpressAi.restBase || !window.postpressAi.nonce) {
      return $.Deferred().reject().promise();
    }

    return $.ajax({
      url: window.postpressAi.restBase + 'postpress-ai/v1/remote-draft-from-composer',
      method: 'POST',
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', window.postpressAi.nonce);
      },
      contentType: 'application/json',
      data: JSON.stringify({
        target_site_id: targetSiteId,
        post_title: payload.post_title,
        post_content: payload.post_content,
        post_excerpt: payload.post_excerpt,
        post_type: payload.post_type,
        meta: payload.meta || {}
      })
    });
  }

  $(document).ready(function() {
    var $targetSelect = $('#postpress-ai-target-site');

    if (!$targetSelect.length) {
      return;
    }

    var currentSite = normalizeSite({
      site_id: 'current',
      name: $targetSelect.attr('data-current-name') || 'This site',
      url: $targetSelect.attr('data-current-url') || '',
      link: $targetSelect.attr('data-current-link') || ''
    });

    updateDestinationDisplay(currentSite);

    $targetSelect.on('change', function() {
      var selectedSite = siteFromOption($(this).find('option:selected'), currentSite);
      updateDestinationDisplay(selectedSite);
    });

    loadSites()
      .done(function(sites) {
        if (!Array.isArray(sites)) {
          return;
        }

        sites.forEach(function(site) {
          if (!site || site.site_id === 'current') {
            return;
          }

          var normalized = normalizeSite({
            site_id: site.site_id,
            name: site.name || site.url || site.site_id,
            url: site.url || '',
            link: site.url || ''
          });

          var label = normalized.name || normalized.url || normalized.site_id;
          if (normalized.url) {
            label += ' (' + normalized.url + ')';
          }

          $('<option>')
            .val(normalized.site_id)
            .text(label)
            .attr('data-site-name', normalized.name)
            .attr('data-site-url', normalized.url)
            .attr('data-site-link', normalized.link)
            .appendTo($targetSelect);
        });

        updateDestinationDisplay(
          siteFromOption($targetSelect.find('option:selected'), currentSite)
        );
      })
      .fail(function() {
        updateDestinationDisplay(
          siteFromOption($targetSelect.find('option:selected'), currentSite)
        );
      });

    $('#postpress-ai-save-draft, #ppa-draft').on('click', function(e) {
      e.preventDefault();

      if (typeof postpressAiCollectComposerPayload !== 'function') {
        console.error('postpressAiCollectComposerPayload() is not defined.');
        return;
      }

      var payload = postpressAiCollectComposerPayload();
      var targetSiteId = $targetSelect.val() || 'current';

      if (targetSiteId === 'current') {
        if (typeof postpressAiSaveLocalDraft === 'function') {
          postpressAiSaveLocalDraft(payload);
          return;
        }

        console.error('postpressAiSaveLocalDraft() is not defined.');
        return;
      }

      saveRemoteDraft(payload, targetSiteId)
        .done(function(response) {
          var msg = 'Draft saved on remote site.';

          if (response && response.target_site && response.target_site.url) {
            msg = 'Draft saved on ' + response.target_site.url + '.';
          }

          if (typeof postpressAiShowNotice === 'function') {
            postpressAiShowNotice(msg, 'success');
          }

          if (
            response &&
            response.remote_post &&
            response.remote_post.edit_link &&
            typeof postpressAiShowLinkNotice === 'function'
          ) {
            postpressAiShowLinkNotice(
              'Open remote draft in a new tab',
              response.remote_post.edit_link
            );
          }
        })
        .fail(function(xhr) {
          var msg = 'Could not save draft to the selected site.';

          if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            msg = xhr.responseJSON.message;
          }

          if (typeof postpressAiShowNotice === 'function') {
            postpressAiShowNotice(msg, 'error');
          }
        });
    });
  });

})(jQuery);
