(function($){

  const cfg = window.FVQA_CFG || {};
  const $doc = $(document);

  /* ---------- Viewport/Keyboard helpers (mobile) ---------- */
  function setVH() {
    // Use innerHeight to compute 1vh equivalent to avoid iOS URL bar issues
    const vh = (window.visualViewport ? window.visualViewport.height : window.innerHeight) * 0.01;
    document.documentElement.style.setProperty('--fvqa-vh', vh + 'px');
  }
  setVH();
  window.addEventListener('resize', setVH);
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', setVH);
  }

  /* ---------- Mobile detection ---------- */
  function isMobile(){
    // Match your CSS breakpoint for phones
    return window.matchMedia && window.matchMedia('(max-width: 600px)').matches;
  }

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

  /** Robust extraction: time hints */
  function extractSeconds(s){
    if(!s) return null;
    s = (s+'').toLowerCase().trim();

    let m = s.match(/\b(\d{1,2}):(\d{2}):(\d{2})\b/);
    if (m) return parseInt(m[1],10)*3600 + parseInt(m[2],10)*60 + parseInt(m[3],10);

    m = s.match(/\b(\d{1,2}):(\d{2})\b/);
    if (m) return parseInt(m[1],10)*60 + parseInt(m[2],10);

    m = s.match(/\b(\d{1,4})\s*(?:m|min|mins|minute|minutes)\b/);
    if (m) return parseInt(m[1],10) * 60;

    m = s.match(/\b(?:at|in|on)?\s*(?:the\s*)?(?:minute|min)\s+(\d{1,4})\b/);
    if (m) return parseInt(m[1],10) * 60;

    m = s.match(/\b(?:the\s*)?(\d{1,4})(?:st|nd|rd|th)?\s+minute\b/);
    if (m) return parseInt(m[1],10) * 60;

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
    const $root = $box.closest('.fvqa-widget');
    const row = $('<div/>', {'class':'fvqa-msg fvqa-'+role}).text(text);
    $box.append(row);
    refreshInputSpace($root);
    requestAnimationFrame(()=>{ $box.scrollTop($box.prop('scrollHeight')); });
  }
  function addMessageHTML($box, role, html){
    const $root = $box.closest('.fvqa-widget');
    const row = $('<div/>', {'class':'fvqa-msg fvqa-'+role}).html(html);
    $box.append(row);
    refreshInputSpace($root);
    requestAnimationFrame(()=>{ $box.scrollTop($box.prop('scrollHeight')); });
  }

  function setThinking($root, on){
    const $thinking = $root.find('.fvqa-thinking');
    if (on) $thinking.removeAttr('hidden'); else $thinking.attr('hidden', true);
    refreshInputSpace($root);
  }

  function refreshInputSpace($root){
    if (!$root || !$root.length) return;
    const $input = $root.find('.fvqa-input');
    if (!$input.length) return;
    const height = Math.round($input.outerHeight(true) || 0);
    if (!height) return;
    const gap = Math.max(height + 18, 72);
    $root.get(0).style.setProperty('--fvqa-input-space', gap + 'px');
  }

  function setBusy($root, on){
    const $controls = $root.find('.fvqa-action-btn, .fvqa-send');
    $root.toggleClass('fvqa-busy', !!on);
    if (on) {
      $controls.prop('disabled', true).attr('aria-disabled', 'true');
    } else {
      $controls.prop('disabled', false).removeAttr('disabled').removeAttr('aria-disabled');
    }
    refreshInputSpace($root);
  }

  function refreshAllInputSpaces(){
    $('.fvqa-widget').each(function(){ refreshInputSpace($(this)); });
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

  /* ---------- Quiz HTML coercion (safety net) ---------- */
  function looksLikeQuizText(text){
    const t = String(text || '');
    if (/<\s*(h[1-6]|p|ul|ol|li|strong|em|blockquote|code)\b/i.test(t)) return false;
    if (/\bQ\d+\./i.test(t)) return true;
    if (/^\s*Answer\s*:/mi.test(t)) return true;
    if (/^\s*Why\s*:/mi.test(t)) return true;
    if (/^\s*(True|False)\s*$/mi.test(t)) return true;
    if (/^\s*(Quick Check|Questions|Review Notes)\b/mi.test(t)) return true;
    return false;
  }
  function coerceQuizHTML(text){
    const src = String(text || '').replace(/\r\n?/g, '\n').trim();
    const lines = src.split('\n');

    let html = [];
    let inUL = false;
    let pbuf = [];

    const flushP = () => {
      if (pbuf.length){
        const s = escapeHtml(pbuf.join(' ').trim());
        if (s) html.push('<p>'+s+'</p>');
        pbuf = [];
      }
    };
    const startUL = () => { if (!inUL){ html.push('<ul>'); inUL=true; } };
    const endUL   = () => { if (inUL){ html.push('</ul>'); inUL=false; } };

    lines.forEach(rawLine => {
      const line = rawLine.trim();
      if (!line){ endUL(); flushP(); return; }

      if (/^questions?$/i.test(line)) { endUL(); flushP(); html.push('<h3>Questions</h3>'); return; }
      if (/^review notes?$/i.test(line)) { endUL(); flushP(); html.push('<h2>Review Notes</h2>'); return; }
      if (/^q\d+\./i.test(line)) { endUL(); flushP(); html.push('<h4>'+escapeHtml(line)+'</h4>'); return; }

      let m = line.match(/^([A-D])\)\s*(.+)$/);
      if (m){ flushP(); startUL(); html.push('<li>'+escapeHtml(m[1]+') '+m[2])+'</li>'); return; }

      if (/^(true|false)$/i.test(line)){ flushP(); startUL(); html.push('<li>'+escapeHtml(line.charAt(0).toUpperCase()+line.slice(1).toLowerCase())+'</li>'); return; }

      m = line.match(/^answer\s*:\s*(.+)$/i);
      if (m){ endUL(); flushP(); html.push('<p><strong>Answer:</strong> '+escapeHtml(m[1])+'</p>'); return; }

      m = line.match(/^why\s*:\s*(.+)$/i);
      if (m){ endUL(); flushP(); html.push('<p><em>Why:</em> '+escapeHtml(m[1])+'</p>'); return; }

      pbuf.push(line);
    });

    endUL(); flushP();

    if (!html.length){ return '<p>'+escapeHtml(src)+'</p>'; }
    const joined = html.join('\n');
    if (!/<h2[^>]*>.*Quick Check/i.test(joined) && /<h4>Q\d+\./i.test(joined)){
      return '<h2>Quick Check</h2><p>Answer the questions below to test your understanding. Each question includes the correct answer and an explanation.</p>\n'+joined;
    }
    return joined;
  }
  function escapeHtml(s){
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  /* ---------- Markdown/HTML rendering ---------- */
  function renderMarkdownSafe(md){
    try {
      let s = String(md || '');
      const looksLikeHTML = /<\/?[a-z][\s\S]*>/i.test(s);
      const haveDOMPurify = !!(window.DOMPurify && window.DOMPurify.sanitize);
      const haveMarked    = !!(window.marked && window.marked.parse);

      if (!looksLikeHTML && looksLikeQuizText(s)) { s = coerceQuizHTML(s); }

      let html;
      if (/<\s*(h[1-6]|p|ul|ol|li|strong|em|blockquote|code|span|small)\b/i.test(s)) {
        html = s;
      } else if (haveMarked) {
        html = window.marked.parse(s);
      } else {
        const esc = s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        html = '<p>'+esc.replace(/\n{2,}/g,'</p><p>').replace(/\n/g,'<br>')+'</p>';
      }

      if (haveDOMPurify) {
        html = window.DOMPurify.sanitize(html, {
          ALLOWED_TAGS: ['h1','h2','h3','h4','p','ul','ol','li','strong','em','code','pre','blockquote','hr','a','br','span','small'],
          ALLOWED_ATTR: ['href','title','target','rel']
        });
      }
      return html;
    } catch (e) {
      return '<p>'+String(md || '').replace(/</g,'&lt;')+'</p>';
    }
  }

  /* ---------- Network ---------- */
  function sendAsk($root, payload){
    const $msgs = $root.find('.fvqa-messages');
    setThinking($root, true);
    setBusy($root, true);

    payload.video_id = payload.video_id || detectVideoId();

    return $.ajax({
      url: (cfg.rest && cfg.rest.url) ? cfg.rest.url : '/wp-json/fvqa/v1/ask',
      method: 'POST',
      headers: { 'X-WP-Nonce': (cfg.rest && cfg.rest.nonce) ? cfg.rest.nonce : '' },
      contentType: 'application/json',
      data: JSON.stringify(buildPayload(payload))
    }).always(function(){
        setThinking($root, false);
        setBusy($root, false);
      })
      .done(function(res){
        if (!res) { addMessage($msgs, 'assistant', 'Sorry, empty response.'); return; }
        if (res.error) { addMessage($msgs, 'assistant', 'Error: ' + res.error); return; }

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
          const p = '<div class="fvqa-audio"><audio controls preload="none" src="'+ String(res.audio_url).replace(/"/g,'&quot;') +'"></audio></div>';
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
    const $root = $(this).closest('.fvqa-widget');
    if ($root.hasClass('fvqa-busy')) return;
    const $text = $root.find('.fvqa-text');
    const q = $text.val().trim();
    if (!q) return;

    const secs = extractSeconds(q);
    addMessage($root.find('.fvqa-messages'), 'user', q);

    const payload = { question: q, mode: 'chat', button_id: '' };
    if (secs !== null) payload.time_hint = secs;

    sendAsk($root, payload);
    $text.val('');
  });

  // Action buttons
  $doc.on('click', '.fvqa-action-btn', function(e){
    e.preventDefault();
    const $btn  = $(this);
    const $root = $btn.closest('.fvqa-widget');
    if ($root.hasClass('fvqa-busy')) return;

    // On mobile: ensure widget is visible and fullscreen before sending
    if (isMobile()){
      if ($root.hasClass('fvqa-hidden')) {
        $root.removeClass('fvqa-hidden');
        syncBubble();
      }
      if (!$root.hasClass('fvqa-fullscreen-on')) {
        $root.addClass('fvqa-fullscreen-on');
        $('html,body').addClass('fvqa-no-scroll');
      }
    }

    const $text = $root.find('.fvqa-text');
    const q = $text.val().trim();
    const secs = extractSeconds(q);
    const label = $btn.text().trim();
    const id = $btn.data('id') || '';
    const wantAudio = !!$btn.data('audio');

    addMessage($root.find('.fvqa-messages'), 'user', (q || '(no text)') + '  — ['+label+']');

    const payload = { question: q, mode: 'button', button_id: id, want_audio: wantAudio };
    if (secs !== null) payload.time_hint = secs;

    sendAsk($root, payload);
    $text.val('');
  });

  // Close → minimize
  $doc.on('click', '.fvqa-close', function(){
    const $root = widgetRoot();
    $root.addClass('fvqa-hidden').removeClass('fvqa-fullscreen-on');
    $('html,body').removeClass('fvqa-no-scroll');
    syncBubble();
    setTimeout(()=>$('.fvqa-bubble').focus(), 0);
  });

  // Fullscreen toggle (true fullscreen + internal scroll + ESC to exit)
  $doc.on('click', '.fvqa-fullscreen', function(){
    const $card = $(this).closest('.fvqa-widget');
    $card.toggleClass('fvqa-fullscreen-on');
    const isOn = $card.hasClass('fvqa-fullscreen-on');
    $('html,body').toggleClass('fvqa-no-scroll', isOn);
    if (isOn) setTimeout(()=> $card.find('.fvqa-text').trigger('focus'), 50);
  });

  $doc.on('keydown', function(e){
    if (e.key === 'Escape') {
      const $card = $('.fvqa-widget.fvqa-fullscreen-on');
      if ($card.length){
        $card.removeClass('fvqa-fullscreen-on');
        $('html,body').removeClass('fvqa-no-scroll');
      }
    }
  });

  // Reveal/restore from bubble
  $doc.on('click', '.fvqa-bubble', function(){
    const $root = widgetRoot();
    $root.removeClass('fvqa-hidden');
    // On mobile, open directly in fullscreen when bubble is tapped
    if (isMobile()){
      $root.addClass('fvqa-fullscreen-on');
      $('html,body').addClass('fvqa-no-scroll');
    }
    syncBubble();
    setTimeout(()=>{ $root.find('.fvqa-text').trigger('focus'); }, 0);
  });

  // Improve keyboard experience on mobile:
  // scroll the input into view when focused
  $doc.on('focus', '.fvqa-text', function(){
    const $root = widgetRoot();
    const $msgs = $root.find('.fvqa-messages');
    setTimeout(()=>{ $msgs.scrollTop($msgs.prop('scrollHeight')); }, 100);
  });

  $(function(){
    // Ensure bubble exists
    ensureBubble();

    // Initial open behavior:
    // - Desktop/tablet: keep current default (widget shown if theme prints it)
    // - Mobile (<=600px): start minimized (hidden), bubble visible
    const $root = widgetRoot();
    if (isMobile()){
      $root.addClass('fvqa-hidden').removeClass('fvqa-fullscreen-on');
      $('html,body').removeClass('fvqa-no-scroll');
    }
    syncBubble();
    setVH();
  });

})(jQuery);
