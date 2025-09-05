(function($){

  const cfg = window.FVQA_CFG || {};
  const $doc = $(document);

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

  // Helper: build payload without nulls (so WP doesn't reject types)
  function buildPayload(base){
    const out = {};
    Object.keys(base).forEach(k => {
      const v = base[k];
      if (v === null || typeof v === 'undefined') return;
      out[k] = v;
    });
    return out;
  }

  function sendAsk($root, payload){
    const $msgs = $root.find('.fvqa-messages');
    setThinking($root, true);

    payload.video_id = payload.video_id || detectVideoId();

    return $.ajax({
      url: cfg.rest.url, // /wp-json/fvqa/v1/ask
      method: 'POST',
      headers: { 'X-WP-Nonce': cfg.rest.nonce },
      contentType: 'application/json',
      data: JSON.stringify(buildPayload(payload))
    }).always(function(){ setThinking($root, false); })
      .done(function(res){
        if (!res) { addMessage($msgs, 'assistant', 'Sorry, empty response.'); return; }
        if (res.error) { addMessage($msgs, 'assistant', 'Error: ' + res.error); return; }

        if (res.answer) addMessage($msgs, 'assistant', res.answer);
        else addMessage($msgs, 'assistant', 'Sorry, I could not produce an answer.');

        if (res.sources && res.sources.length) {
          addMessage($msgs, 'meta', 'Sources: ' + res.sources.join(' • '));
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

  // Send message (chat mode) — we do NOT send a time_hint; server infers from text if needed
  $doc.on('click', '.fvqa-send', function(e){
    e.preventDefault();
    const $root = widgetRoot();
    const $text = $root.find('.fvqa-text');
    const q = $text.val().trim();
    if (!q) return;

    addMessage($root.find('.fvqa-messages'), 'user', q);

    const payload = {
      question:  q,
      mode:      'chat',
      button_id: ''
    };

    sendAsk($root, payload);
    $text.val('');
  });

  // Action buttons — whole-video default: NO time_hint sent
  $doc.on('click', '.fvqa-action-btn', function(e){
    e.preventDefault();
    const $btn  = $(this);
    const $root = $btn.closest('.fvqa-widget');
    const $text = $root.find('.fvqa-text');
    const q = $text.val().trim();
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
