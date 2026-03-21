(function($, win, doc){
  'use strict';

  function cleanUrl(url){
    return String(url || '').replace(/^https?:\/\//, '').replace(/\/$/, '');
  }

  function getDomain(url){
    var cleaned = cleanUrl(url);
    return cleaned.split('/')[0] || '';
  }

  function ucFirst(value){
    value = String(value || '').trim();
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : '';
  }

  function buildLabel(site){
    var name   = String(site.name || '').trim() || 'Unnamed site';
    var domain = String(site.domain || getDomain(site.url || '')).trim();
    var siteId = String(site.site_id || '').trim();

    var label = name;
    if(domain){
      label += ' - ' + domain;
    }
    if(siteId){
      label += ' (Site ID ' + siteId + ')';
    }

    return label;
  }

  function buildMeta(site){
    var bits   = [];
    var domain = String(site.domain || getDomain(site.url || '')).trim();
    var siteId = String(site.site_id || '').trim();
    var status = ucFirst(String(site.status || 'active').toLowerCase());

    if(domain) bits.push(domain);
    if(siteId) bits.push('Site ID ' + siteId);
    if(status) bits.push(status);

    return bits.join(' · ');
  }

  function normalizeSite(raw){
    raw = raw || {};

    var url    = String(raw.url || raw.link || '').trim();
    var domain = String(raw.domain || getDomain(url)).trim();

    var site = {
      value: raw.is_current ? 'current' : String(raw.site_id || '').trim(),
      site_id: String(raw.site_id || '').trim(),
      name: String(raw.name || raw.title || domain || url || 'Unnamed site').trim(),
      url: url,
      domain: domain,
      status: String(raw.status || 'active').trim().toLowerCase() || 'active',
      is_current: !!raw.is_current,
      label: String(raw.label || '').trim(),
      link: url || (domain ? ('https://' + domain + '/') : '')
    };

    if(!site.label){
      site.label = buildLabel(site);
    }

    site.meta = buildMeta(site);
    return site;
  }

  function isComposer(){
    return !!doc.getElementById('ppa-composer');
  }

  function getCurrentSite($select){
    return normalizeSite({
      is_current: true,
      site_id: $select.attr('data-current-site-id') || '',
      name: $select.attr('data-current-name') || 'This site',
      url: $select.attr('data-current-url') || win.location.origin,
      status: 'active',
      label: $select.attr('data-current-label') || ''
    });
  }

  function setOptionData($opt, site){
    if(!$opt || !$opt.length || !site) return;

    $opt
      .attr('data-site-id', site.site_id || '')
      .attr('data-site-name', site.name || '')
      .attr('data-site-url', site.url || '')
      .attr('data-site-domain', site.domain || '')
      .attr('data-site-status', site.status || 'active')
      .attr('data-site-label', site.label || '')
      .attr('data-site-link', site.link || '')
      .text(site.label || site.name || site.domain || 'Site');
  }

  function siteFromOption($opt, fallbackCurrent){
    if(!$opt || !$opt.length){
      return fallbackCurrent || null;
    }

    var site = normalizeSite({
      is_current: ($opt.val() || 'current') === 'current',
      site_id: $opt.attr('data-site-id') || '',
      name: $opt.attr('data-site-name') || '',
      url: $opt.attr('data-site-url') || $opt.attr('data-site-link') || '',
      domain: $opt.attr('data-site-domain') || '',
      status: $opt.attr('data-site-status') || 'active',
      label: $opt.attr('data-site-label') || ''
    });

    site.value = String($opt.val() || 'current');

    if(site.value === 'current' && fallbackCurrent){
      return fallbackCurrent;
    }

    return site;
  }

  function setHeader(site){
    if(!site) return;

    var $link = $('#ppa-target-site-link');
    var $url  = $('#ppa-target-site-url');
    var $meta = $('#ppa-target-site-meta');

    if($link.length){
      $link.text(site.name || site.label || '');
      if(site.link){
        $link
          .attr('href', site.link)
          .attr('target', '_blank')
          .attr('rel', 'noopener noreferrer');
      }
    }

    var metaText = site.meta || buildMeta(site);

    if($meta.length){
      $meta.text(metaText).show();
      if($url.length){
        $url.text('').hide();
      }
    } else if($url.length){
      $url.text(metaText).show();
    }
  }

  function getNonce(){
    if(win.PPA && win.PPA.nonce) return String(win.PPA.nonce);
    if(win.wpApiSettings && win.wpApiSettings.nonce) return String(win.wpApiSettings.nonce);
    if(win.ppaAdmin && win.ppaAdmin.nonce) return String(win.ppaAdmin.nonce);

    var composer = doc.getElementById('ppa-composer');
    if(composer && composer.getAttribute('data-ppa-nonce')){
      return String(composer.getAttribute('data-ppa-nonce'));
    }

    var nonceEl = doc.getElementById('ppa-nonce');
    if(nonceEl && nonceEl.value){
      return String(nonceEl.value);
    }

    var dataEl = doc.querySelector('[data-ppa-nonce]');
    if(dataEl){
      return String(dataEl.getAttribute('data-ppa-nonce') || '');
    }

    return '';
  }

  function getAjaxUrl(){
    if(win.PPA && win.PPA.ajaxUrl) return String(win.PPA.ajaxUrl);
    if(win.PPA && win.PPA.ajax) return String(win.PPA.ajax);
    if(win.ppaAdmin && win.ppaAdmin.ajaxurl) return String(win.ppaAdmin.ajaxurl);
    if(win.ajaxurl) return String(win.ajaxurl);
    return '/wp-admin/admin-ajax.php';
  }

  function buildAjaxActionUrl(action){
    var base = getAjaxUrl();
    return base + (base.indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(String(action || ''));
  }

  function sanitizeMessage(text){
    var s = String(text || '')
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    if(!s) return '';
    if(s.length > 220) s = s.slice(0, 217) + '...';
    return s;
  }

  function parseJsonResponse(resp){
    return resp.text().then(function(text){
      var trimmed = String(text || '').trim();
      var data = {};

      if(trimmed){
        try {
          data = JSON.parse(trimmed);
        } catch (e) {
          data = { message: trimmed };
        }
      }

      if(!resp.ok){
        var message = '';

        if(data && typeof data === 'object'){
          if(typeof data.message === 'string' && data.message.trim()){
            message = data.message;
          } else if(data.data && typeof data.data.message === 'string' && data.data.message.trim()){
            message = data.data.message;
          }
        }

        message = sanitizeMessage(message) || ('HTTP ' + resp.status);

        var error = new Error(message);
        error.status = resp.status;
        error.response = data;
        throw error;
      }

      return data;
    });
  }

  function apiGetSites(){
    if(win.wp && win.wp.apiFetch){
      return win.wp.apiFetch({ path: '/postpress-ai/v1/sites' });
    }

    return fetch('/wp-json/postpress-ai/v1/sites', {
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': getNonce() }
    }).then(parseJsonResponse);
  }

  function apiRemoteDraft(payload, targetSiteId){
    var body = {
      target_site_id: targetSiteId,
      post_title: payload.post_title,
      post_content: payload.post_content,
      post_excerpt: payload.post_excerpt,
      post_type: payload.post_type,
      meta: payload.meta || {}
    };

    var nonce = getNonce();
    var headers = {
      'Content-Type': 'application/json; charset=UTF-8',
      'Accept': 'application/json, text/plain, */*',
      'X-Requested-With': 'XMLHttpRequest'
    };

    if(nonce){
      headers['X-WP-Nonce'] = nonce;
      headers['X-PPA-Nonce'] = nonce;
    }

    return fetch(buildAjaxActionUrl('ppa_remote_draft_from_composer'), {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: JSON.stringify(body)
    }).then(parseJsonResponse);
  }

  function collectPayload(){
    var subject = ($('#ppa-subject').val() || '').trim();
    var title   = ($('#ppa-title').val() || subject).trim();
    var excerpt = ($('#ppa-excerpt').val() || '').trim();

    var pane = doc.getElementById('ppa-preview-pane');
    var html = pane ? (pane.innerHTML || '').trim() : '';

    return {
      post_title: title,
      post_content: html,
      post_excerpt: excerpt,
      post_type: 'post',
      meta: {}
    };
  }

  function showMsg(text){
    var $msg = $('#ppa-toolbar-msg');
    if($msg.length){
      $msg.text(text || '');
    }
  }

  function extractErrorMessage(err){
    var i, candidate, msg;

    if(!err) return 'Unknown error.';
    if(typeof err === 'string'){
      msg = sanitizeMessage(err);
      return msg || 'Unknown error.';
    }

    var candidates = [];
    if(err.message) candidates.push(err.message);
    if(err.response && err.response.message) candidates.push(err.response.message);
    if(err.response && err.response.data && err.response.data.message) candidates.push(err.response.data.message);
    if(err.data && err.data.message) candidates.push(err.data.message);

    for(i = 0; i < candidates.length; i++){
      candidate = sanitizeMessage(candidates[i]);
      if(candidate) return candidate;
    }

    try {
      msg = sanitizeMessage(JSON.stringify(err));
      return msg || 'Unknown error.';
    } catch (e) {
      return 'Unknown error.';
    }
  }

  $(function(){
    if(!isComposer()) return;

    var $select = $('#postpress-ai-target-site');
    if(!$select.length) return;

    var current = getCurrentSite($select);
    var confirmedTargets = {};

    var $currentOpt = $select.find('option[value="current"]').first();
    setOptionData($currentOpt, current);
    setHeader(current);

    $select.off('change.ppaRemoteDrafts').on('change.ppaRemoteDrafts', function(){
      var site = siteFromOption($(this).find('option:selected'), current);
      setHeader(site || current);
    });

    apiGetSites()
      .then(function(sites){
        if(!Array.isArray(sites)) return;

        var currentHost = getDomain(win.location.origin);

        $select.find('option').not('[value="current"]').remove();

        sites.forEach(function(raw){
          var site = normalizeSite(raw);

          if(site.is_current){
            current = site;
            current.value = 'current';
            setOptionData($currentOpt, current);
            return;
          }

          if(!site.site_id) return;
          if(site.domain && site.domain === currentHost) return;

          var $opt = $('<option>').val(site.site_id);
          setOptionData($opt, site);
          $opt.appendTo($select);
        });

        setHeader(siteFromOption($select.find('option:selected'), current) || current);
      })
      .catch(function(err){
        console.warn('PPA remote drafts: /sites failed', err);
      });

    $(doc).off('click.ppaRemoteDrafts').on('click.ppaRemoteDrafts', '#ppa-draft, #postpress-ai-save-draft, #ppa-store', function(e){
      var targetValue = String($select.val() || 'current');

      if(targetValue === 'current'){
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();

      var site = siteFromOption($select.find('option:selected'), current);
      if(!site || !site.site_id){
        showMsg('Remote save failed. Selected target site could not be resolved.');
        return false;
      }

      var payload = collectPayload();

      if(!payload.post_content){
        showMsg('Generate Preview first, then Save Draft (Store).');
        return false;
      }

      if(!confirmedTargets[targetValue]){
        var approved = win.confirm('Save this draft to ' + site.label + '?');
        if(!approved){
          showMsg('Remote save canceled for ' + site.label + '.');
          return false;
        }
        confirmedTargets[targetValue] = true;
      }

      showMsg('Saving draft to ' + site.label + '...');

      var remoteEditor = null;
      try{
        remoteEditor = win.open('', 'ppaRemoteDraftEditor');
        if(remoteEditor){
          remoteEditor.document.open();
          remoteEditor.document.write('<!doctype html><title>Opening remote draft...</title><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px;"></body>');
          remoteEditor.document.close();
          remoteEditor.document.body.textContent = 'Creating remote draft on ' + site.label + '...';
        }
      } catch(openErr){
        remoteEditor = null;
      }

      apiRemoteDraft(payload, targetValue)
        .then(function(resp){
          var message = (resp && resp.message) ? resp.message : ('Draft saved to ' + site.label + '.');
          showMsg(message);

          var editLink = '';
          if(resp && resp.remote_post && resp.remote_post.edit_link){
            editLink = resp.remote_post.edit_link;
          } else if(resp && resp.edit_link){
            editLink = resp.edit_link;
          }

          if(editLink){
            if(remoteEditor && !remoteEditor.closed){
              try{
                remoteEditor.opener = null;
              } catch(openerErr){}
              remoteEditor.location = editLink;
              try{
                remoteEditor.focus();
              } catch(focusErr){}
            } else {
              win.open(editLink, 'ppaRemoteDraftEditor');
            }
          } else if(remoteEditor && !remoteEditor.closed){
            remoteEditor.close();
          }
        })
        .catch(function(err){
          var message = extractErrorMessage(err);
          console.warn('PPA remote drafts: remote save failed', err);
          showMsg('Remote save to ' + site.label + ' failed: ' + message);

          if(remoteEditor && !remoteEditor.closed){
            try{
              remoteEditor.document.open();
              remoteEditor.document.write('<!doctype html><title>Remote draft failed</title><body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px;"></body>');
              remoteEditor.document.close();
              remoteEditor.document.body.textContent = 'Remote draft failed: ' + message;
            } catch(renderErr){
              remoteEditor.close();
            }
          }
        });

      return false;
    });

    console.log('PPA remote drafts: explicit target identity ready');
  });

})(jQuery, window, document);