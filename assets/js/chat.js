(function($){
/* ========= Small utilities ========= */
function nowISO(){ return new Date().toISOString(); }

function appendMsg($box, text, role){
  var label = (role==='user'?'You':'Tutor');
  var $m = $('<div class="msg">');
  $m.append($('<div class="meta">').text(label+' • '+nowISO()));
  $m.append($('<div class="text">').text(text));
  $box.append($m);
  $box.scrollTop($box[0].scrollHeight);
}

/* ========= Thinking indicator ========= */
function addThinking($wrap){
  // Remove any existing thinking indicator first
  clearThinking($wrap);

  var $box = $wrap.find('.fvqa-messages');
  var $m = $('<div class="msg thinking" aria-live="polite" aria-busy="true">');
  $m.append($('<div class="meta">').text('Tutor • preparing answer…'));
  var $text = $('<div class="text">');
  var $typing = $('<span class="typing" aria-hidden="true"><span></span><span></span><span></span></span>');
  $text.append('Thinking ').append($typing);
  $m.append($text);
  $box.append($m);
  $box.scrollTop($box[0].scrollHeight);

  // Mark the widget as busy for a11y
  $wrap.find('.fvqa-panel').attr('aria-busy','true');

  // Save handle so we can remove later
  $wrap.data('fvqaThinkingEl', $m);
}

function clearThinking($wrap){
  var $t = $wrap.data('fvqaThinkingEl');
  if($t && $t.remove){ $t.remove(); }
  $wrap.removeData('fvqaThinkingEl');
  $wrap.find('.fvqa-panel').removeAttr('aria-busy');
}

/* ========= Busy state for send button ========= */
function setBusy($wrap, busy){
  var $btn = $wrap.find('.fvqa-send');
  if(busy){ $btn.prop('disabled', true).addClass('is-busy'); }
  else { $btn.prop('disabled', false).removeClass('is-busy'); }
}

/* ========= Open/close panel ========= */
function openPanel($wrap, open){
  var $panel = $wrap.find('.fvqa-panel');
  var $bubble = $wrap.find('.fvqa-bubble');
  if(open){ $panel.removeAttr('hidden'); $bubble.attr('aria-expanded','true'); }
  else { $panel.attr('hidden',true); $bubble.attr('aria-expanded','false'); }
}

/* ========= UI bindings ========= */
$(document).on('click','.fvqa-bubble',function(){
  var $wrap = $(this).closest('.fvqa-widget');
  var isOpen = !$wrap.find('.fvqa-panel').attr('hidden');
  openPanel($wrap, !isOpen);
});

$(document).on('click','.fvqa-close',function(){
  var $wrap = $(this).closest('.fvqa-widget');
  openPanel($wrap,false);
});

$(document).on('keydown','#fvqa-text',function(e){
  if(e.key==='Enter'){ $(this).closest('.fvqa-widget').find('.fvqa-send').click(); }
});

/* ========= Send handler ========= */
$(document).on('click','.fvqa-send',function(){
  var $wrap = $(this).closest('.fvqa-widget');
  var videoId = ($wrap.data('video-id') || '').toString();
  var $input = $wrap.find('#fvqa-text');
  var $box   = $wrap.find('.fvqa-messages');
  var q = ($input.val() || '').trim();
  if(!q){ return; }
  if(q.length > 800){ q = q.slice(0,800); }

  appendMsg($box,q,'user');
  $input.val('');
  setBusy($wrap,true);
  addThinking($wrap); // ← show “thinking…”

  var headers = { 'Content-Type': 'application/json' };
  // Send BOTH if present; server accepts either
  if(FVQA.wpNonce){ headers['X-WP-Nonce'] = FVQA.wpNonce; }
  if(FVQA.publicNonce){ headers['X-FVQA-Nonce'] = FVQA.publicNonce; }

  $.ajax({
    method:'POST',
    url: FVQA.rest,
    headers: headers,
    data: JSON.stringify({ video_id: videoId, question: q }),
    xhrFields: { withCredentials: true },   // ensure cookies for LocalWP / BuddyBoss
    success: function(res){
      clearThinking($wrap);
      var ans = res && res.answer ? res.answer : 'No answer.';
      var src = (res && res.sources && res.sources.length) ? ('\nSources: ' + res.sources.join(' • ')) : '';
      appendMsg($box, ans + src, 'bot');
    },
    error: function(xhr){
      clearThinking($wrap);
      var msg = 'Error: ';
      if(xhr && xhr.responseJSON && xhr.responseJSON.error){
        msg += xhr.responseJSON.error;
      } else if (xhr && xhr.responseText) {
        msg += xhr.responseText;
      } else if (xhr && xhr.statusText) {
        msg += xhr.statusText;
      } else {
        msg += 'Request failed.';
      }
      appendMsg($box, msg, 'bot');
    },
    complete: function(){ setBusy($wrap,false); }
  });
});
})(jQuery);
