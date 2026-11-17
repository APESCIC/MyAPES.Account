/**
 * MyAPES Account guest ticket embed loader (#684).
 * Injects an iframe for data-myapes-ticket-embed[data-public-key] and resizes via postMessage.
 */
(function () {
  'use strict';

  var SOURCE = 'myapes-ticket-embed';

  function baseUrl() {
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].src || '';
      var marker = '/embed/tickets/loader.js';
      var idx = src.indexOf(marker);
      if (idx !== -1) {
        return src.slice(0, idx);
      }
    }
    return '';
  }

  function mount(el) {
    var key = el.getAttribute('data-public-key');
    if (!key) return;
    var root = baseUrl();
    if (!root) return;

    var iframe = document.createElement('iframe');
    iframe.title = 'Open a ticket';
    iframe.setAttribute('loading', 'lazy');
    iframe.style.width = '100%';
    iframe.style.border = '0';
    iframe.style.minHeight = '320px';
    iframe.style.overflow = 'hidden';
    el.appendChild(iframe);

    var tokenUrl = root + '/embed/tickets/' + encodeURIComponent(key) + '/token';
    fetch(tokenUrl, {
      method: 'GET',
      credentials: 'omit',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        if (!res.ok) throw new Error('token');
        return res.json();
      })
      .then(function (data) {
        var url = data.form_url + '?token=' + encodeURIComponent(data.token) +
          '&parent_origin=' + encodeURIComponent(window.location.origin);
        iframe.src = url;
      })
      .catch(function () {
        el.textContent = 'This ticket form could not be loaded.';
      });

    window.addEventListener('message', function (event) {
      var data = event.data;
      if (!data || data.source !== SOURCE) return;
      if (iframe.contentWindow !== event.source) return;
      if (data.type === 'height' && typeof data.height === 'number') {
        iframe.style.height = Math.max(240, data.height) + 'px';
      }
      if (data.type === 'submitted') {
        el.dispatchEvent(new CustomEvent('myapes-ticket-submitted', { detail: data }));
      }
    });
  }

  function boot() {
    var nodes = document.querySelectorAll('[data-myapes-ticket-embed][data-public-key]');
    for (var i = 0; i < nodes.length; i++) {
      if (!nodes[i].getAttribute('data-mounted')) {
        nodes[i].setAttribute('data-mounted', '1');
        mount(nodes[i]);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
