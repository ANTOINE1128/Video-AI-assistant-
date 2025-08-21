
(function($){
    function panelFor($bubble){ return $(".fvqa-panel[data-video-id='"+$bubble.data('video-id')+"']"); }

    function addMsg($panel, who, text){
        var $box = $panel.find('.fvqa-messages');
        var html = '<div class="fvqa-msg '+who+'">'+$('<div>').text(text).html()+'</div>';
        $box.append(html); $box.scrollTop($box[0].scrollHeight);
    }
    function addCites($panel, cites){
        if(!cites||!cites.length) return;
        var $box = $panel.find('.fvqa-messages');
        var html = '<div class="fvqa-cite">Sources: ' + cites.map(function(c){ return '['+secToTime(c.start)+']'; }).join(' ') + '</div>';
        $box.append(html);
    }
    function secToTime(s){ var m=Math.floor(s/60), ss=('0'+(s%60)).slice(-2); return m+':'+ss; }

    $(document).on('click','.fvqa-bubble', function(){ var $p=panelFor($(this)); $p.toggle(); });
    $(document).on('click','.fvqa-send', function(){
        var $p = $(this).closest('.fvqa-panel');
        var q = $p.find('input').val().trim(); if(!q) return;
        $p.find('input').val('');
        addMsg($p,'user',q);
        addMsg($p,'bot','Thinking…');
        $.ajax({
            url: FVQA.rest.url + '/ask?_wpnonce='+FVQA.rest.nonce,
            method:'POST',
            contentType:'application/json',
            data: JSON.stringify({ video_id: $p.data('video-id'), question: q }),
        }).done(function(r){
            $p.find('.fvqa-msg.bot:last').remove();
            addMsg($p,'bot', r.answer || '');
            addCites($p, r.citations || []);
        }).fail(function(xhr){
            $p.find('.fvqa-msg.bot:last').remove();
            var msg = 'Request failed';
            try{ msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : msg; }catch(e){}
            addMsg($p,'bot','Error: '+msg);
        });
    });
})(jQuery);
