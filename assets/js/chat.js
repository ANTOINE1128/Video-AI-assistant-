(function($){
function appendMsg($box, text, role){
  var $m = $('<div class="msg">').text((role==='user'?'You: ':'Tutor: ')+text);
  $box.append($m);
  $box.scrollTop($box[0].scrollHeight);
}
$(document).on('click','.fvqa-bubble',function(){
  $(this).siblings('.fvqa-panel').toggle();
});
$(document).on('click','.fvqa-send',function(){
  var $wrap = $(this).closest('.fvqa-widget');
  var videoId = $wrap.data('video-id') || '';
  var $input = $wrap.find('input');
  var $box = $wrap.find('.fvqa-messages');
  var q = $input.val().trim();
  if(!q){return;}
  appendMsg($box,q,'user');
  $input.val('');
  $.ajax({
    method:'POST',
    url: FVQA.rest,
    beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', FVQA.nonce); },
    data: JSON.stringify({ video_id: videoId, question: q }),
    contentType: 'application/json',
    success: function(res){
      var ans = res && res.answer ? res.answer : 'No answer.';
      var src = res && res.sources ? (' Sources: '+res.sources.join(' • ')) : '';
      appendMsg($box, ans + (src?('\n'+src):''), 'bot');
    },
    error: function(xhr){
      var msg = 'Error: ' + (xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : xhr.statusText);
      appendMsg($box, msg, 'bot');
    }
  });
});
})(jQuery);
