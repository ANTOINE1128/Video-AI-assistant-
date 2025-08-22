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
  $wrap.find('.fvqa-panel').attr('aria-busy','true');
  $wrap.data('fvqaThinkingEl', $m);
}
function clearThinking($wrap){
  var $t = $wrap.data('fvqaThinkingEl');
  if($t && $t.remove){ $t.remove(); }
  $wrap.removeData('fvqaThinkingEl');
  $wrap.find('.fvqa-panel').removeAttr('aria-busy');
}

/* ========= Busy state ========= */
function setBusy($wrap, busy){
  var $btn = $wrap.find('.fvqa-send');
  if(busy){ $btn.prop('disabled', true).addClass('is-busy'); }
  else { $btn.prop('disabled', false).removeClass('is-busy'); }
}

/* ========= Panel open/close/fullscreen ========= */
function openPanel($wrap, open){
  var $panel = $wrap.find('.fvqa-panel');
  var $bubble = $wrap.find('.fvqa-bubble');
  if(open){
    $panel.removeAttr('hidden');
    $bubble.attr('aria-expanded','true');
    setTimeout(function(){ $wrap.find('#fvqa-text').trigger('focus'); }, 0);
  } else {
    $panel.attr('hidden',true);
    $bubble.attr('aria-expanded','false');
    exitFullscreen($wrap);
  }
}
function toggleFullscreen($wrap){
  var $panel = $wrap.find('.fvqa-panel');
  var $btn   = $wrap.find('.fvqa-fullscreen');
  var isFs = $panel.hasClass('is-fullscreen');
  if(isFs){
    $panel.removeClass('is-fullscreen');
    $btn.attr('aria-pressed','false').attr('aria-label','Enter fullscreen').attr('title','Fullscreen');
  }else{
    $panel.addClass('is-fullscreen');
    $btn.attr('aria-pressed','true').attr('aria-label','Exit fullscreen').attr('title','Exit fullscreen');
  }
}
function exitFullscreen($wrap){
  var $panel = $wrap.find('.fvqa-panel');
  var $btn   = $wrap.find('.fvqa-fullscreen');
  if($panel.hasClass('is-fullscreen')){
    $panel.removeClass('is-fullscreen');
    $btn.attr('aria-pressed','false').attr('aria-label','Enter fullscreen').attr('title','Fullscreen');
  }
}

/* ========= UI bindings (event delegation) ========= */
$(document).on('click','.fvqa-bubble',function(e){
  e.preventDefault(); e.stopPropagation();
  var $wrap = $(this).closest('.fvqa-widget');
  var isOpen = !$wrap.find('.fvqa-panel').attr('hidden');
  openPanel($wrap, !isOpen);
});

$(document).on('click','.fvqa-close',function(e){
  e.preventDefault(); e.stopPropagation();
  var $wrap = $(this).closest('.fvqa-widget');
  openPanel($wrap,false);
});

$(document).on('click','.fvqa-fullscreen',function(e){
  e.preventDefault(); e.stopPropagation();
  var $wrap = $(this).closest('.fvqa-widget');
  toggleFullscreen($wrap);
});

// Close on ESC, exit fullscreen on ESC as well
$(document).on('keydown',function(e){
  if(e.key === 'Escape'){
    var $wrap = $('.fvqa-widget');
    if(!$wrap.length) return;
    var $panel = $wrap.find('.fvqa-panel');
    if($panel.hasClass('is-fullscreen')){
      exitFullscreen($wrap);
    } else if(!$panel.attr('hidden')) {
      openPanel($wrap,false);
    }
  }
});

// Prevent clicks inside panel from bubbling to page
$(document).on('click','.fvqa-panel',function(e){
  e.stopPropagation();
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
  addThinking($wrap);

  var headers = { 'Content-Type': 'application/json' };
  if(FVQA.wpNonce){ headers['X-WP-Nonce'] = FVQA.wpNonce; }
  if(FVQA.publicNonce){ headers['X-FVQA-Nonce'] = FVQA.publicNonce; }

  $.ajax({
    method:'POST',
    url: FVQA.rest,
    headers: headers,
    data: JSON.stringify({ video_id: videoId, question: q }),
    xhrFields: { withCredentials: true },
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
