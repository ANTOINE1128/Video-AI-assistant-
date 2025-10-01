(function($){

  const cfg = window.FVQA_CFG || {};
  const $doc = $(document);

  /* ---------- Vimeo ID detection ---------- */
  function detectVideoId(){
    const dataEl = document.querySelector('[data-vimeo-id]');
    if (dataEl && /^\d{7,12}$/.test(dataEl.getAttribute('data-vimeo-id'))) return dataEl.getAttribute('data-vimeo-id');

    const ifr = document.querySelector('iframe[src*="vimeo.com"]');
    if (ifr) {
      const u = ifr.getAttribute('src') || '';
      const m = u.match(/(?:video\/|vimeo\.com\/)(\d{7,12})/);
      if (m) return m[1];
    }

    const a = document.querySelector('a[href*="vimeo.com"]');
    if (a) {
      const href = a.getAttribute('href') || '';
      const m = href.match(/vimeo\.com\/(?:manage\/videos\/)?(\d{7,12})/);
      if (m) return m[1];
    }

    const html = document.documentElement.innerHTML;
    const any = html.match(/vimeo\.com\/(?:video\/)?(\d{7,12})/);
    if (any) return any[1];

    return null;
  }

  /** Robust extraction: understands “minute 12”, “in minute 10”, “12 min”, “12m”, “at 12:00”, etc. */
  function extractSeconds(s){
    if(!s) return null;
    s = (s+'').toLowerCase().trim();

    // 1) H:MM:SS
    let m = s.match(/\b(\d{1,2}):(\d{2}):(\d{2})\b/);
    if (m) return parseInt(m[1],10)*3600 + parseInt(m[2],10)*60 + parseInt(m[3],10);

    // 2) MM:SS
    m = s.match(/\b(\d{1,2}):(\d{2})\b/);
    if (m) return parseInt(m[1],10)*60 + parseInt(m[2],10);

    // 3) “12 minutes”, “12 min”, “12m”, “12mins”
    m = s.match(/\b(\d{1,4})\s*(?:m|min|mins|minute|minutes)\b/);
    if (m) return parseInt(m[1],10) * 60;

    // 4) “minute 12”, “in minute 10”, “at the minute 3”
    m = s.match(/\b(?:at|in|on)?\s*(?:the\s*)?(?:minute|min)\s+(\d{1,4})\b/);
    if (m) return parseInt(m[1],10) * 60;

    // 5) Ordinals: “the 12th minute”
    m = s.match(/\b(?:the\s*)?(\d{1,4})(?:st|nd|rd|th)?\s+minute\b/);
    if (m) return parseInt(m[1],10) * 60;

    // 6) Seconds forms
    m = s.match(/\b(\d{1,5})\s*s(?:ec|econds?)?\b/);
    if (m) return parseInt(m[1],10);

    return null;
  }

  /* ---------- DOM helpers ---------- */
  function widgetRoot(){ return $('.fvqa-widget'); }

  function ensureBubble(){
    let $bubble = $('.fvqa-bubble');
    if (!$bubble.length){
      $bubble = $('<button/>', {'class':'fvqa-bubble','type':'button','aria-label':'Open lecture Q&A','title':'Open Q&A'})
        .append('<span class="fvqa-bubble-ico" aria-hidden="true">💬</span><span class="fvqa-bubble-label">Q&A</span>');
      $('body').append($bubble);
    }
    return $bubble;
  }
  function syncBubble(){
    const hidden = widgetRoot().hasClass('fvqa-hidden');
    ensureBubble().toggleClass('show', hidden);
  }

  function addMessage($box, role, text){
    const row = $('<div/>', {'class':'fvqa-msg fvqa-'+role}).text(text);
    $box.append(row);
    $box.scrollTop($box.prop('scrollHeight'));
  }
  function addMessageHTML($box, role, html){
    const row = $('<div/>', {'class':'fvqa-msg fvqa-'+role}).html(html);
    $box.append(row);
    $box.scrollTop($box.prop('scrollHeight'));
  }

  function setThinking($root, on){
    const $thinking = $root.find('.fvqa-thinking');
    if (on) $thinking.removeAttr('hidden'); else $thinking.attr('hidden', true);
  }

  function buildPayload(base){
    const out = {};
    Object.keys(base).forEach(k => {
      const v = base[k];
      if (v === null || typeof v === 'undefined') return;
      out[k] = v;
    });
    return out;
  }

  /* ---------- Markdown/HTML rendering (with graceful fallback) ---------- */
  function renderMarkdownSafe(md){
    try {
      const s = String(md || '');
      const looksLikeHTML = /<\/?[a-z][\s\S]*>/i.test(s); // crude but effective

      const haveDOMPurify = !!(window.DOMPurify && window.DOMPurify.sanitize);
      const haveMarked    = !!(window.marked && window.marked.parse);

      let html;

      if (looksLikeHTML) {
        // Already HTML → just sanitize below
        html = s;
      } else if (haveMarked) {
        // Markdown → HTML
        html = window.marked.parse(s);
      } else {
        // Plain fallback (no marked available)
        const esc = s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        html = '<p>'+esc.replace(/\n{2,}/g,'</p><p>').replace(/\n/g,'<br>')+'</p>';
      }

      if (haveDOMPurify) {
        html = window.DOMPurify.sanitize(html, {
          ALLOWED_TAGS: [
            'h1','h2','h3','h4',
            'p','ul','ol','li',
            'strong','em','code','pre',
            'blockquote','hr','a','br'
          ],
          ALLOWED_ATTR: ['href','title','target','rel']
        });
      }
      return html;
    } catch (e) {
      // Ultimate fallback: plain text
      return '<p>'+String(md || '').replace(/</g,'&lt;')+'</p>';
    }
  }

  /* ---------- Network ---------- */
  function sendAsk($root, payload){
    const $msgs = $root.find('.fvqa-messages');
    setThinking($root, true);

    payload.video_id = payload.video_id || detectVideoId();

    return $.ajax({
      url: (cfg.rest && cfg.rest.url) ? cfg.rest.url : '/wp-json/fvqa/v1/ask',
      method: 'POST',
      headers: { 'X-WP-Nonce': (cfg.rest && cfg.rest.nonce) ? cfg.rest.nonce : '' },
      contentType: 'application/json',
      data: JSON.stringify(buildPayload(payload))
    }).always(function(){ setThinking($root, false); })
      .done(function(res){
        if (!res) {
          addMessage($msgs, 'assistant', 'Sorry, empty response.');
          return;
        }
        if (res.error) {
          addMessage($msgs, 'assistant', 'Error: ' + res.error);
          return;
        }
        if (res.answer) {
          const html = renderMarkdownSafe(res.answer);
          addMessageHTML($msgs, 'assistant', html);
        } else {
          addMessage($msgs, 'assistant', 'Sorry, I could not produce an answer.');
        }

        if (res.sources && res.sources.length) {
          const srcLine = Array.isArray(res.sources) ? res.sources.join(' • ') : String(res.sources);
          const html = '<div class="fvqa-sources" aria-label="Sources"><small><em>Sources:</em> '+srcLine+'</small></div>';
          addMessageHTML($msgs, 'meta', html);
        }

        if (res.audio_url) {
          const p = '<div class="fvqa-audio"><audio controls preload="none" src="'+
                    String(res.audio_url).replace(/"/g,'&quot;') +
                    '"></audio></div>';
          addMessageHTML($msgs, 'assistant', p);
        } else if (res.audio_error) {
          addMessage($msgs, 'meta', 'Audio error: ' + res.audio_error);
        }
      }).fail(function(xhr){
        let msg = 'Error';
        try {
          if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
          else if (xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
          else msg = xhr.responseText || String(xhr.status);
        } catch(e){}
        addMessage($msgs, 'assistant', 'Error: ' + msg);
      });
  }

  /* ---------- UI events ---------- */

  // Send message (chat mode)
  $doc.on('click', '.fvqa-send', function(e){
    e.preventDefault();
    const $root = widgetRoot();
    const $text = $root.find('.fvqa-text');
    const q = $text.val().trim();
    if (!q) return;

    const secs = extractSeconds(q);
    addMessage($root.find('.fvqa-messages'), 'user', q);

    const payload = {
      question:  q,
      mode:      'chat',
      button_id: ''
    };
    if (secs !== null) payload.time_hint = secs;

    sendAsk($root, payload);
    $text.val('');
  });

  // Action buttons
  $doc.on('click', '.fvqa-action-btn', function(e){
    e.preventDefault();
    const $btn  = $(this);
    const $root = $btn.closest('.fvqa-widget');
    const $text = $root.find('.fvqa-text');
    const q = $text.val().trim();
    const secs = extractSeconds(q);
    const label = $btn.text().trim();
    const id = $btn.data('id') || '';
    const wantAudio = !!$btn.data('audio');

    addMessage($root.find('.fvqa-messages'), 'user', (q || '(no text)') + '  — ['+label+']');

    const payload = {
      question:   q,
      mode:       'button',
      button_id:  id,
      want_audio: wantAudio
    };
    if (secs !== null) payload.time_hint = secs;

    sendAsk($root, payload);
    $text.val('');
  });

  // Close → minimize
  $doc.on('click', '.fvqa-close', function(){
    const $root = widgetRoot();
    $root.addClass('fvqa-hidden');
    syncBubble();
    setTimeout(()=>$('.fvqa-bubble').focus(), 0);
  });

  // Fullscreen toggle
  $doc.on('click', '.fvqa-fullscreen', function(){
    $(this).closest('.fvqa-widget').toggleClass('fvqa-fullscreen-on');
  });

  // Bubble click → restore
  $doc.on('click', '.fvqa-bubble', function(){
    const $root = widgetRoot();
    $root.removeClass('fvqa-hidden');
    syncBubble();
    setTimeout(()=>{ $root.find('.fvqa-text').trigger('focus'); }, 0);
  });

  $(function(){
    ensureBubble(); syncBubble();
  });

})(jQuery);
