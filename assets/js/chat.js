(function($){

  const cfg = window.FVQA_CFG || {};
  const $doc = $(document);

  // Find Vimeo ID on the page (client-side)
  function detectVideoId(){
    const dataEl = document.querySelector('[data-vimeo-id]');
    if (dataEl && /^\d{7,12}$/.test(dataEl.getAttribute('data-vimeo-id'))) {
      return dataEl.getAttribute('data-vimeo-id');
    }
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

  // Extract timestamp → seconds
  function extractSeconds(s){
    if(!s) return null;
    s = (s+'').toLowerCase();
    const m3 = s.match(/\b(\d{1,2}):(\d{2}):(\d{2})\b/);
    if (m3) return parseInt(m3[1],10)*3600 + parseInt(m3[2],10)*60 + parseInt(m3[3],10);
    const m2 = s.match(/\b(\d{1,2}):(\d{2})\b/);
    if (m2) return parseInt(m2[1],10)*60 + parseInt(m2[2],10);
    const ms = s.match(/\b(\d{1,5})\s*s(ec|econds)?\b/);
    if (ms) return parseInt(ms[1],10);
    return null;
  }

  function widgetRoot(){
    return $('.fvqa-widget');
  }

  function ensureBubble(){
    // Create the launcher bubble once
    let $bubble = $('.fvqa-bubble');
    if (!$bubble.length){
      $bubble = $('<button/>', {
        'class':'fvqa-bubble',
        'type':'button',
        'aria-label':'Open lecture Q&A',
        'title':'Open Q&A'
      }).append('<span class="fvqa-bubble-ico" aria-hidden="true">💬</span><span class="fvqa-bubble-label">Q&A</span>');
      $('body').append($bubble);
    }
    return $bubble;
  }

  function syncBubble(){
    const $root   = widgetRoot();
    const hidden  = $root.hasClass('fvqa-hidden');
    const $bubble = ensureBubble();
    $bubble.toggleClass('show', hidden);
  }

  function addMessage($box, role, text){
    const row = $('<div/>', {'class':'fvqa-msg fvqa-'+role}).text(text);
    $box.append(row);
    $box.scrollTop($box.prop('scrollHeight'));
  }

  function setThinking($root, on){
    const $thinking = $root.find('.fvqa-thinking');
    if (on) $thinking.removeAttr('hidden'); else $thinking.attr('hidden', true);
  }

  function sendAsk($root, payload){
    const $msgs = $root.find('.fvqa-messages');
    setThinking($root, true);

    // Always attach video_id if we can detect it
    payload.video_id = payload.video_id || detectVideoId();

    return $.ajax({
      url: cfg.rest.url,
      method: 'POST',
      headers: { 'X-WP-Nonce': cfg.rest.nonce },
      contentType: 'application/json',
      data: JSON.stringify(payload)
    }).always(function(){
      setThinking($root, false);
    }).done(function(res){
      if (res && res.answer) {
        addMessage($msgs, 'assistant', res.answer);
        if (res.sources && res.sources.length) {
          addMessage($msgs, 'meta', 'Sources: ' + res.sources.join(' • '));
        }
      } else {
        addMessage($msgs, 'assistant', 'Sorry, I could not produce an answer.');
      }
    }).fail(function(xhr){
      let msg = 'Error';
      try { msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : xhr.responseText; } catch(e){}
      addMessage($msgs, 'assistant', 'Error: ' + msg);
    });
  }

  // Send message
  $doc.on('click', '.fvqa-send', function(e){
    e.preventDefault();
    const $root = widgetRoot();
    const $text = $root.find('.fvqa-text');
    const q = $text.val().trim();
    if (!q) return;

    const secs = extractSeconds(q);
    addMessage($root.find('.fvqa-messages'), 'user', q);

    sendAsk($root, {
      question: q,
      time_hint: secs,
      action_id: null
    });

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
    addMessage($root.find('.fvqa-messages'), 'user', (q || '(no text)') + '  — ['+label+']');

    sendAsk($root, {
      question: q,
      time_hint: secs,
      action_id: $btn.data('id')
    });

    $text.val('');
  });

  // Header: Close → MINIMIZE (not toggle away forever)
  $doc.on('click', '.fvqa-close', function(){
    const $root = widgetRoot();
    $root.addClass('fvqa-hidden');      // hide widget
    syncBubble();                       // show bubble
    // return focus to the bubble for accessibility
    setTimeout(()=>$('.fvqa-bubble').focus(), 0);
  });

  // Header: Fullscreen toggle
  $doc.on('click', '.fvqa-fullscreen', function(){
    $(this).closest('.fvqa-widget').toggleClass('fvqa-fullscreen-on');
  });

  // Bubble click → restore widget
  $doc.on('click', '.fvqa-bubble', function(){
    const $root = widgetRoot();
    $root.removeClass('fvqa-hidden');
    syncBubble(); // hide bubble
    // focus the textarea for quick typing
    setTimeout(()=>{ $root.find('.fvqa-text').trigger('focus'); }, 0);
  });

  // Initialize bubble visibility on load
  $(function(){
    ensureBubble();
    syncBubble();
  });

})(jQuery);
