/* Farhat Video Q&A — Chat UI + robust centered PDF export (PDF only) */
(function ($) {
  "use strict";

  const cfg = window.FVQA_CFG || {};
  const $doc = $(document);

  /* ---------- Viewport helpers (mobile) ---------- */
  function setVH() {
    const vh = (window.visualViewport ? window.visualViewport.height : window.innerHeight) * 0.01;
    document.documentElement.style.setProperty("--fvqa-vh", vh + "px");
  }
  setVH();
  window.addEventListener("resize", setVH);
  if (window.visualViewport) window.visualViewport.addEventListener("resize", setVH);

  /* ---------- Mobile detection ---------- */
  function isMobile() { return window.matchMedia && window.matchMedia("(max-width: 600px)").matches; }

  /* ---------- Vimeo ID detection ---------- */
  function detectVideoId() {
    const dataEl = document.querySelector("[data-vimeo-id]");
    if (dataEl && /^\d{7,12}$/.test(dataEl.getAttribute("data-vimeo-id"))) return dataEl.getAttribute("data-vimeo-id");

    const ifr = document.querySelector('iframe[src*="vimeo.com"]');
    if (ifr) {
      const u = ifr.getAttribute("src") || "";
      const m = u.match(/(?:video\/|vimeo\.com\/)(\d{7,12})/);
      if (m) return m[1];
    }
    const a = document.querySelector('a[href*="vimeo.com"]');
    if (a) {
      const href = a.getAttribute("href") || "";
      const m = href.match(/vimeo\.com\/(?:manage\/videos\/)?(\d{7,12})/);
      if (m) return m[1];
    }
    const html = document.documentElement.innerHTML;
    const any = html.match(/vimeo\.com\/(?:video\/)?(\d{7,12})/);
    if (any) return any[1];
    return null;
  }

  /* ---------- Time-hint extraction ---------- */
  function extractSeconds(s) {
    if (!s) return null;
    s = (s + "").toLowerCase().trim();

    let m = s.match(/\b(\d{1,2}):(\d{2}):(\d{2})\b/);
    if (m) return parseInt(m[1], 10) * 3600 + parseInt(m[2], 10) * 60 + parseInt(m[3], 10);

    m = s.match(/\b(\d{1,2}):(\d{2})\b/);
    if (m) return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);

    m = s.match(/\b(\d{1,4})\s*(?:m|min|mins|minute|minutes)\b/);
    if (m) return parseInt(m[1], 10) * 60;

    m = s.match(/\b(?:at|in|on)?\s*(?:the\s*)?(?:minute|min)\s+(\d{1,4})\b/);
    if (m) return parseInt(m[1], 10) * 60;

    m = s.match(/\b(?:the\s*)?(\d{1,4})(?:st|nd|rd|th)?\s+minute\b/);
    if (m) return parseInt(m[1], 10) * 60;

    m = s.match(/\b(\d{1,5})\s*s(?:ec|econds?)?\b/);
    if (m) return parseInt(m[1], 10);
    return null;
  }

  /* ---------- DOM helpers ---------- */
  function widgetRoot() { return $(".fvqa-widget"); }

  function ensureBubble() {
    let $bubble = $(".fvqa-bubble");
    if (!$bubble.length) {
      $bubble = $("<button/>", {
        class: "fvqa-bubble",
        type: "button",
        "aria-label": "Open lecture Q&A",
        title: "Open Q&A",
      }).append('<span class="fvqa-bubble-ico" aria-hidden="true">💬</span><span class="fvqa-bubble-label">Q&A</span>');
      $("body").append($bubble);
    }
    return $bubble;
  }
  function syncBubble() {
    const hidden = widgetRoot().hasClass("fvqa-hidden");
    ensureBubble().toggleClass("show", hidden);
  }

  function setThinking($root, on) {
    const $thinking = $root.find(".fvqa-thinking");
    if (on) $thinking.removeAttr("hidden"); else $thinking.attr("hidden", true);
    refreshInputSpace($root);
  }
  function refreshInputSpace($root) {
    if (!$root || !$root.length) return;
    const $input = $root.find(".fvqa-input");
    if (!$input.length) return;
    const height = Math.round($input.outerHeight(true) || 0);
    if (!height) return;
    const gap = Math.max(height + 18, 72);
    $root.get(0).style.setProperty("--fvqa-input-space", gap + "px");
  }
  function setBusy($root, on) {
    const $controls = $root.find(".fvqa-action-btn, .fvqa-send");
    $root.toggleClass("fvqa-busy", !!on);
    if (on) $controls.prop("disabled", true).attr("aria-disabled", "true");
    else $controls.prop("disabled", false).removeAttr("disabled").removeAttr("aria-disabled");
    refreshInputSpace($root);
  }
  function buildPayload(base) {
    const out = {};
    Object.keys(base).forEach((k) => {
      const v = base[k];
      if (v === null || typeof v === "undefined") return;
      out[k] = v;
    });
    return out;
  }

  /* ---------- Markdown/HTML rendering ---------- */
  function looksLikeQuizText(text) {
    const t = String(text || "");
    if (/<\s*(h[1-6]|p|ul|ol|li|strong|em|blockquote|code|pre|table|tr|td|th)\b/i.test(t)) return false;
    if (/\bQ\d+\./i.test(t)) return true;
    if (/^\s*Answer\s*:/mi.test(t)) return true;
    if (/^\s*Why\s*:/mi.test(t)) return true;
    if (/^\s*(True|False)\s*$/mi.test(t)) return true;
    if (/^\s*(Quick Check|Questions|Review Notes)\b/mi.test(t)) return true;
    return false;
  }
  function escapeHtml(s){
    return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;");
  }
  function coerceQuizHTML(text){
    const src = String(text || "").replace(/\r\n?/g, "\n").trim();
    const lines = src.split("\n");
    let html = [], inUL=false, pbuf=[];
    const flushP=()=>{ if(pbuf.length){ const s=escapeHtml(pbuf.join(" ").trim()); if(s) html.push("<p>"+s+"</p>"); pbuf=[]; } };
    const startUL=()=>{ if(!inUL){ html.push("<ul>"); inUL=true; } };
    const endUL=()=>{ if(inUL){ html.push("</ul>"); inUL=false; } };
    lines.forEach(raw=>{
      const line = raw.trim();
      if(!line){ endUL(); flushP(); return; }
      if(/^questions?$/i.test(line)){ endUL(); flushP(); html.push("<h3>Questions</h3>"); return; }
      if(/^review notes?$/i.test(line)){ endUL(); flushP(); html.push("<h2>Review Notes</h2>"); return; }
      if(/^q\d+\./i.test(line)){ endUL(); flushP(); html.push("<h4>"+escapeHtml(line)+"</h4>"); return; }
      let m = line.match(/^([A-D])\)\s*(.+)$/);
      if(m){ flushP(); startUL(); html.push("<li>"+escapeHtml(m[1]+") "+m[2])+"</li>"); return; }
      if(/^(true|false)$/i.test(line)){ flushP(); startUL(); html.push("<li>"+escapeHtml(line.charAt(0).toUpperCase()+line.slice(1).toLowerCase())+"</li>"); return; }
      m = line.match(/^answer\s*:\s*(.+)$/i);
      if(m){ endUL(); flushP(); html.push("<p><strong>Answer:</strong> "+escapeHtml(m[1])+"</p>"); return; }
      m = line.match(/^why\s*:\s*(.+)$/i);
      if(m){ endUL(); flushP(); html.push("<p><em>Why:</em> "+escapeHtml(m[1])+"</p>"); return; }
      pbuf.push(line);
    });
    endUL(); flushP();
    if(!html.length) return "<p>"+escapeHtml(src)+"</p>";
    const joined = html.join("\n");
    if (!/<h2[^>]*>.*Quick Check/i.test(joined) && /<h4>Q\d+\./i.test(joined)){
      return '<h2>Quick Check</h2><p>Answer the questions below to test your understanding.</p>\n'+joined;
    }
    return joined;
  }
  function renderMarkdownSafe(md) {
    try {
      let s = String(md || "");
      const looksLikeHTML = /<\/?[a-z][\s\S]*>/i.test(s);
      const haveDOMPurify = !!(window.DOMPurify && window.DOMPurify.sanitize);
      const haveMarked = !!(window.marked && window.marked.parse);
      if (!looksLikeHTML && looksLikeQuizText(s)) s = coerceQuizHTML(s);

      let html;
      if (/<\s*(h[1-6]|p|ul|ol|li|strong|em|code|pre|blockquote|hr|a|br|span|small|table|thead|tbody|tr|th|td)\b/i.test(s)) {
        html = s;
      } else if (haveMarked) {
        html = window.marked.parse(s);
      } else {
        const esc = s.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");
        html = "<p>"+esc.replace(/\n{2,}/g,"</p><p>").replace(/\n/g,"<br>")+"</p>";
      }
      if (haveDOMPurify) {
        html = window.DOMPurify.sanitize(html, {
          ALLOWED_TAGS: ["h1","h2","h3","h4","p","ul","ol","li","strong","em","code","pre","blockquote","hr","a","br","span","small","table","thead","tbody","tr","th","td"],
          ALLOWED_ATTR: ["href","title","target","rel"]
        });
      }
      return html;
    } catch(e){ return "<p>"+String(md||"").replace(/</g,"&lt;")+"</p>"; }
  }

  /* ---------- Messages ---------- */
  function addMessage($box, role, text) {
    const $root = $box.closest(".fvqa-widget");
    const row = $("<div/>", { class: "fvqa-msg fvqa-"+role }).attr("data-plain", String(text || "")).text(text);
    $box.append(row);
    refreshInputSpace($root);
    requestAnimationFrame(()=>{ $box.scrollTop($box.prop("scrollHeight")); });
    revealExportIfAnyAnswer($root);
  }
  function addMessageHTML($box, role, html) {
    const $root = $box.closest(".fvqa-widget");
    const row = $("<div/>", { class: "fvqa-msg fvqa-"+role }).attr("data-html", String(html || "")).html(html);
    $box.append(row);
    refreshInputSpace($root);
    requestAnimationFrame(()=>{ $box.scrollTop($box.prop("scrollHeight")); });
    revealExportIfAnyAnswer($root);
  }

  /* ---------- Network ---------- */
  function sendAsk($root, payload) {
    const $msgs = $root.find(".fvqa-messages");
    setThinking($root, true); setBusy($root, true);
    payload.video_id = payload.video_id || detectVideoId();

    return $.ajax({
      url: (cfg.rest && cfg.rest.url) ? cfg.rest.url : "/wp-json/fvqa/v1/ask",
      method: "POST",
      headers: { "X-WP-Nonce": (cfg.rest && cfg.rest.nonce) || "" },
      contentType: "application/json",
      data: JSON.stringify(buildPayload(payload))
    })
    .always(function(){ setThinking($root,false); setBusy($root,false); })
    .done(function(res){
      if(!res){ addMessage($msgs,"assistant","Sorry, empty response."); return; }
      if(res.error){ addMessage($msgs,"assistant","Error: "+res.error); return; }

      if(res.answer){
        const html = renderMarkdownSafe(res.answer);
        addMessageHTML($msgs,"assistant", html);
      } else {
        addMessage($msgs,"assistant","Sorry, I could not produce an answer.");
      }

      if(res.sources && res.sources.length){
        const srcLine = Array.isArray(res.sources) ? res.sources.join(" • ") : String(res.sources);
        addMessageHTML($msgs,"meta", '<div class="fvqa-sources"><small><em>Sources:</em> '+srcLine+'</small></div>');
      }

      if(res.audio_url){
        addMessageHTML($msgs,"assistant",
          '<div class="fvqa-audio"><audio controls preload="none" src="'+String(res.audio_url).replace(/"/g,'&quot;')+'"></audio></div>');
      } else if(res.audio_error){
        addMessage($msgs,"meta","Audio error: "+res.audio_error);
      }
    })
    .fail(function(xhr){
      let msg="Error";
      try{
        if (xhr.responseJSON && xhr.responseJSON.message) msg=xhr.responseJSON.message;
        else if (xhr.responseJSON && xhr.responseJSON.error) msg=xhr.responseJSON.error;
        else msg = xhr.responseText || String(xhr.status);
      }catch(e){}
      addMessage($msgs,"assistant","Error: "+msg);
    });
  }

  /* ---------- One-time loaders (CDN only) ---------- */
  async function loadScriptInto(docOrWindow, src){
    return new Promise((resolve,reject)=>{
      const doc = docOrWindow.document || docOrWindow;
      const s = doc.createElement("script");
      s.src = src; s.async = true;
      s.onload = resolve;
      s.onerror = () => reject(new Error("Failed to load "+src));
      doc.head.appendChild(s);
    });
  }
  async function ensureJsPDF(){
    if (window.jspdf && window.jspdf.jsPDF) return window.jspdf;
    await loadScriptInto(window, "https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js");
    return window.jspdf;
  }
  async function ensureHtml2CanvasIn(win){
    if (win.html2canvas) return win.html2canvas;
    await loadScriptInto(win, "https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js");
    return win.html2canvas;
  }

  /* ---------- Export button (PDF only) ---------- */
  function ensureExportButton() {
    const $hdr = $(".fvqa-widget .fvqa-header .fvqa-header-btns");
    if (!$hdr.length) return null;

    // remove any previous variants & duplicates
    $hdr.find(".fvqa-export, .fvqa-export-main, .fvqa-export-docx, .fvqa-export-menu").remove();

    let $btn = $hdr.find(".fvqa-export-pdf");
    if (!$btn.length) {
      $btn = $("<button/>", {
        class: "fvqa-btn fvqa-export-pdf",
        type: "button",
        title: "Download conversation (PDF)",
        "aria-label": "Download conversation as PDF",
        hidden: true
      });
      $hdr.prepend($btn);
    } else if ($btn.length > 1) {
      $btn.not(":first").remove();
      $btn = $hdr.find(".fvqa-export-pdf").first();
    }
    return $btn;
  }

  function revealExportIfAnyAnswer($root) {
    const hasAssistant = $root.find(".fvqa-messages .fvqa-assistant").length > 0;
    const $btn = ensureExportButton();
    if ($btn) { if (hasAssistant) $btn.removeAttr("hidden"); else $btn.attr("hidden",""); }
  }

  $doc.on("click", ".fvqa-export-pdf", function (e) {
    e.preventDefault();
    const $root = $(this).closest(".fvqa-widget");
    exportToPDF($root);
  });

  /* ---------- Printable HTML (brand + centered) ---------- */
  function buildPrintableHTML($root){
    const brand = Object.assign({
      name: (cfg.brand && cfg.brand.name) || "Farhat Lectures",
      website: (cfg.brand && cfg.brand.website) || location.origin,
      email: (cfg.brand && cfg.brand.email) || "",
      logoUrl: (cfg.brand && cfg.brand.logoUrl) || "",
      accent: (cfg.brand && cfg.brand.accent) || "#4da6ff"
    }, cfg.brand || {});

    const $msgs = $root.find(".fvqa-messages .fvqa-msg");
    const hasAssistant = $msgs.filter(".fvqa-assistant").length > 0;
    if (!hasAssistant) return "";

    const rows = [];
    $msgs.each(function(){
      const $m = $(this);
      const isUser = $m.hasClass("fvqa-user");
      const isAssistant = $m.hasClass("fvqa-assistant");
      if (!isUser && !isAssistant) return;

      const role = isUser ? "USER" : "ASSISTANT";
      const html = $m.attr("data-html") || $m.html();
      rows.push(
        '<section class="block '+(isUser?'user':'assistant')+'">'+
          '<div class="who">'+role+'</div>'+
          '<div class="bubble">'+(html||"")+'</div>'+
        '</section>'
      );
    });

    const vid = detectVideoId() || "conversation";
    const exported = new Date().toLocaleString();

    return '<!doctype html><html><head><meta charset="utf-8" />' +
      '<title>'+brand.name+' — Conversation Export</title>' +
      '<style>'+
      'html,body{margin:0;background:#fff;color:#0b1426}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,"Noto Sans",sans-serif}'+
      '.sheet{width:100%;min-height:100vh;display:flex;justify-content:center;align-items:flex-start;background:#fff}'+
      '.page{width:700px;margin:0 auto;padding:24px 20px;box-sizing:border-box}'+
      '.header{display:flex;align-items:center;gap:12px;margin-bottom:10px;border-bottom:2px solid '+brand.accent+'22;padding-bottom:10px}'+
      '.logo{width:36px;height:36px;object-fit:contain;border-radius:8px;border:1px solid '+brand.accent+'33;background:#fff}'+
      '.brand{font-size:16px;font-weight:800;letter-spacing:.2px}.sub{font-size:11px;color:#334}.badge{display:inline-block;font-size:10px;padding:2px 8px;border-radius:999px;background:'+brand.accent+'14;border:1px solid '+brand.accent+'66;color:#0b1426;margin-top:6px}'+
      '.info{font-size:11px;color:#2b3c55;margin:8px 0 14px}.info .lab{color:#456}.divider{height:1px;background:linear-gradient(90deg,'+brand.accent+'33,transparent);margin:8px 0 12px}'+
      '.block{margin:10px 0;break-inside:auto;-webkit-column-break-inside:avoid;page-break-inside:auto}'+
      '.who{font-weight:800;font-size:10px;letter-spacing:.6px;color:#0e1a33;margin-bottom:6px;text-transform:uppercase}'+
      '.bubble{padding:10px 12px;border-radius:10px;border:1px solid #dbe5ff;background:#f8fbff;color:#0b1426;font-size:12px;line-height:1.55;overflow-wrap:anywhere;word-break:break-word}'+
      '.block.user .bubble{background:#f6f9ff;border-color:#cfe0ff}.block.assistant .bubble{background:#f8fbff;border-color:#d9e6ff}'+
      '.bubble h1,.bubble h2,.bubble h3,.bubble h4{color:#071327;margin:.45em 0 .3em} .bubble p{margin:.35em 0}'+
      '.bubble ul,.bubble ol{margin:.4em 0 .7em 1.2em} .bubble li{margin:.15em 0}'+
      '.bubble blockquote{margin:.5em 0;padding:8px 12px;border-left:3px solid '+brand.accent+';background:#f2f8ff;color:#1a2b44}'+
      '.bubble table{width:100%;border-collapse:collapse} .bubble th,.bubble td{border:1px solid #e2e8ff;padding:6px 8px;font-size:11px}'+
      '.footer{text-align:center;font-size:10px;color:#4a5a74;margin-top:10px}'+
      '</style></head><body>' +
      '<div class="sheet"><div class="page">' +
      '<div class="header">'+
        (brand.logoUrl ? '<img class="logo" src="'+brand.logoUrl+'" alt="Logo">' :
         '<div class="logo" style="display:grid;place-items:center;font-weight:800;color:'+brand.accent+'">FL</div>')+
        '<div><div class="brand">'+brand.name+'</div>'+
          '<div class="sub">Conversation Export • '+new Date().toISOString().split("T")[0]+'</div>'+
          '<div class="badge">Video ID: '+vid+'</div></div>'+
      '</div>'+
      '<div class="info"><span class="lab">Page:</span> '+escapeHtml(document.title)+'<br>'+
      '<span class="lab">URL:</span> '+escapeHtml(location.href)+'<br>'+
      (brand.email ? '<span class="lab">Contact:</span> '+escapeHtml(brand.email)+'<br>' : '')+
      '<span class="lab">Exported:</span> '+escapeHtml(new Date().toLocaleString())+'</div>'+
      '<div class="divider"></div>'+rows.join("\n")+
      '<div class="footer">Generated by Farhat Video Q&A'+(brand.website?(' • '+escapeHtml(brand.website)):'')+'</div>'+
      '</div></div></body></html>';
  }

  /* ---------- PDF export (sliced + centered) ---------- */
  async function exportToPDF($root){
    const html = buildPrintableHTML($root);
    if (!html){ alert("Nothing to export yet. Ask a question first."); return; }

    const jspdf = await ensureJsPDF();

    // sandbox iframe
    const iframe = document.createElement("iframe");
    iframe.style.position="fixed"; iframe.style.left="-100000px"; iframe.style.top="0";
    iframe.style.width="1200px"; iframe.style.height="1600px"; iframe.setAttribute("aria-hidden","true");
    document.body.appendChild(iframe);

    const idoc = iframe.contentDocument;
    idoc.open(); idoc.write(html); idoc.close();

    await new Promise(r=>setTimeout(r,80));
    const imgs = Array.from(idoc.images || []);
    await Promise.all(imgs.map(img => img.complete ? Promise.resolve() : new Promise(res => { img.onload = img.onerror = res; })));

    const h2c = await ensureHtml2CanvasIn(iframe.contentWindow);

    const { jsPDF } = jspdf;
    const pdf = new jsPDF({ unit:"pt", format:"a4", orientation:"portrait" });

    const pageW = pdf.internal.pageSize.getWidth();
    const pageH = pdf.internal.pageSize.getHeight();
    const margin = 36;
    const contentWpt = pageW - margin*2;

    const pxPerPt = 96/72;
    const contentWpx = Math.floor(contentWpt * pxPerPt);

    const pageEl = idoc.querySelector(".page") || idoc.body;
    pageEl.style.width = contentWpx + "px";
    pageEl.style.margin = "0 auto";

    const canvas = await h2c(pageEl, { backgroundColor:"#ffffff", scale:2, useCORS:true, windowWidth:contentWpx });

    const imgWpx = canvas.width;
    const imgHpx = canvas.height;
    const ptPerPx = contentWpt / imgWpx;
    const pageHpx = Math.floor((pageH - margin*2) / ptPerPx);

    let y = 0;
    while (y < imgHpx) {
      const sliceHpx = Math.min(pageHpx, imgHpx - y);
      const slice = document.createElement("canvas");
      slice.width = imgWpx; slice.height = sliceHpx;
      slice.getContext("2d").drawImage(canvas, 0, y, imgWpx, sliceHpx, 0, 0, imgWpx, sliceHpx);

      const imgData = slice.toDataURL("image/jpeg", 0.98);
      const sliceHpt = sliceHpx * ptPerPx;

      const xCentered = (pageW - contentWpt)/2;
      pdf.addImage(imgData, "JPEG", xCentered, margin, contentWpt, sliceHpt, undefined, "FAST");

      y += sliceHpx;
      if (y < imgHpx) pdf.addPage();
    }

    iframe.remove();

    const d=new Date(), pad2=n=>String(n).padStart(2,"0");
    const filename = `farhat-lectures.${detectVideoId()||"conversation"}_${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}_${pad2(d.getHours())}${pad2(d.getMinutes())}${pad2(d.getSeconds())}.pdf`;
    pdf.save(filename);
  }

  /* ---------- UI events ---------- */
  $doc.on("click", ".fvqa-send", function(e){
    e.preventDefault();
    const $root = $(this).closest(".fvqa-widget");
    if ($root.hasClass("fvqa-busy")) return;
    const $text = $root.find(".fvqa-text");
    const q = $text.val().trim();
    if (!q) return;

    const secs = extractSeconds(q);
    addMessage($root.find(".fvqa-messages"), "user", q);
    const payload = { question:q, mode:"chat", button_id:"" };
    if (secs !== null) payload.time_hint = secs;
    sendAsk($root, payload);
    $text.val("");
  });

  $doc.on("click", ".fvqa-action-btn", function(e){
    e.preventDefault();
    const $btn = $(this);
    const $root = $btn.closest(".fvqa-widget");
    if ($root.hasClass("fvqa-busy")) return;

    if (isMobile()){
      if ($root.hasClass("fvqa-hidden")) { $root.removeClass("fvqa-hidden"); syncBubble(); }
      if (!$root.hasClass("fvqa-fullscreen-on")) { $root.addClass("fvqa-fullscreen-on"); $('html,body').addClass('fvqa-no-scroll'); }
    }

    const $text = $root.find(".fvqa-text");
    const q = $text.val().trim();
    const secs = extractSeconds(q);
    const label = $btn.text().trim();
    const id = $btn.data("id") || "";
    const wantAudio = !!$btn.data("audio");

    addMessage($root.find(".fvqa-messages"), "user", (q || "(no text)") + "  — ["+label+"]");
    const payload = { question:q, mode:"button", button_id:id, want_audio:wantAudio };
    if (secs !== null) payload.time_hint = secs;
    sendAsk($root, payload);
    $text.val("");
  });

  $doc.on("click", ".fvqa-close", function(){
    const $root = widgetRoot();
    $root.addClass("fvqa-hidden").removeClass("fvqa-fullscreen-on");
    $("html,body").removeClass("fvqa-no-scroll");
    syncBubble();
    setTimeout(()=>$('.fvqa-bubble').focus(), 0);
  });

  $doc.on("click", ".fvqa-fullscreen", function(){
    const $card = $(this).closest(".fvqa-widget");
    $card.toggleClass("fvqa-fullscreen-on");
    const isOn = $card.hasClass("fvqa-fullscreen-on");
    $("html,body").toggleClass("fvqa-no-scroll", isOn);
    if (isOn) setTimeout(()=> $card.find(".fvqa-text").trigger("focus"), 50);
  });

  $doc.on("keydown", function(e){
    if (e.key === "Escape") {
      const $card = $(".fvqa-widget.fvqa-fullscreen-on");
      if ($card.length){ $card.removeClass("fvqa-fullscreen-on"); $('html,body').removeClass('fvqa-no-scroll'); }
    }
  });

  $doc.on("click", ".fvqa-bubble", function(){
    const $root = widgetRoot();
    $root.removeClass("fvqa-hidden");
    if (isMobile()){ $root.addClass("fvqa-fullscreen-on"); $('html,body').addClass('fvqa-no-scroll'); }
    syncBubble();
    setTimeout(()=>{ $root.find(".fvqa-text").trigger("focus"); }, 0);
  });

  $doc.on("focus", ".fvqa-text", function(){
    const $root = widgetRoot();
    const $msgs = $root.find(".fvqa-messages");
    setTimeout(()=>{ $msgs.scrollTop($msgs.prop("scrollHeight")); }, 100);
  });

  /* ---------- Init ---------- */
  $(function(){
    ensureBubble();
    const $root = widgetRoot();
    if (isMobile()){
      $root.addClass("fvqa-hidden").removeClass("fvqa-fullscreen-on");
      $("html,body").removeClass("fvqa-no-scroll");
    }
    syncBubble();
    setVH();

    const $exp = ensureExportButton();
    if ($exp) $exp.attr("hidden","");
  });

})(jQuery);
