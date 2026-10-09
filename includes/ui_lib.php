<?php
/**
 * ظاهر مشترک:
 *  1) افکت ساده روی همه بخش‌های لینک‌دار و دکمه‌ها (ui_fx_css)
 *  2) نمودارهای سبک بدون کتابخانه خارجی: خطی (ch_line)، دایره‌ای (ch_pie)، دونات (ch_donut)
 *     نمودار با SVG در مرورگر کشیده می‌شود (واکنش‌گرا، راهنمای شناور با نگه‌داشتن ماوس/لمس)
 *     و برای دسترس‌پذیری یک جدول مخفی از داده‌ها هم کنارش هست.
 *
 * استفاده: یک بار ui_fx_css() را در <head> چاپ کنید؛ سپس هر جا echo ch_line(...) / ch_donut(...).
 */

/** رنگ‌های دسته‌ای (به ترتیب ثابت؛ هرگز چرخشی نیست) */
function ch_palette()
{
    return ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
}

function ch_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * روزهای اخیر برای محور افقی: ['keys' => ['2026-09-01', ...], 'labels' => ['۶/۱۰', ...]]
 */
function ch_days($n = 30)
{
    $keys = []; $labels = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $ts = strtotime(date('Y-m-d') . ' -' . $i . ' day');
        $keys[] = date('Y-m-d', $ts);
        $j = function_exists('biz_jdate') ? biz_jdate($ts) : date('Y/m/d', $ts);
        $p = explode('/', $j);
        $l = count($p) === 3 ? ((int)$p[1] . '/' . (int)$p[2]) : $j;
        $labels[] = strtr($l, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
    return ['keys' => $keys, 'labels' => $labels];
}

/**
 * شمارش/جمع روزانه با یک کوئری GROUP BY روی تاریخ. $sql باید دو ستون d (تاریخ) و v (مقدار) برگرداند
 * و یک پارامتر از-تاریخ (?) در آخر پارامترها داشته باشد. خروجی: آرایه هم‌طول $keys.
 */
function ch_daily($pdo, $sql, array $params, array $keys)
{
    $out = array_fill_keys($keys, 0);
    try {
        $s = $pdo->prepare($sql);
        $s->execute(array_merge($params, [$keys[0] . ' 00:00:00']));
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $d = substr((string)$r['d'], 0, 10);
            if (isset($out[$d])) $out[$d] += (float)$r['v'];
        }
    } catch (\Throwable $e) { error_log('[CH] daily: ' . $e->getMessage()); }
    return array_values($out);
}

function ch_table($head, array $rows)
{
    $h = '<table class="chx-sr"><thead><tr>';
    foreach ($head as $c) $h .= '<th>' . ch_h($c) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) { $h .= '<tr>'; foreach ($r as $c) $h .= '<td>' . ch_h($c) . '</td>'; $h .= '</tr>'; }
    return $h . '</tbody></table>';
}

/**
 * نمودار خطی.
 * $series = [['name' => 'ویجت', 'data' => [..]], ...]  (حداکثر ۴ سری؛ هم‌طول $labels)
 * $opt: height (پیش‌فرض 220)، unit (مثلاً «پیام» یا «تومان»)، empty (متن نبود داده)
 */
function ch_line(array $labels, array $series, array $opt = [])
{
    $pal = ch_palette();
    $s = [];
    foreach (array_slice(array_values($series), 0, 4) as $i => $x) {
        $s[] = ['n' => (string)($x['name'] ?? ''), 'c' => (string)($x['color'] ?? $pal[$i]), 'd' => array_map(fn($v) => round((float)$v, 2), array_values($x['data'] ?? []))];
    }
    $data = ['t' => 'line', 'l' => array_values(array_map('strval', $labels)), 's' => $s, 'u' => (string)($opt['unit'] ?? ''), 'h' => (int)($opt['height'] ?? 220), 'e' => (string)($opt['empty'] ?? 'هنوز داده‌ای برای نمایش نیست.')];
    $rows = [];
    foreach ($data['l'] as $i => $l) { $r = [$l]; foreach ($s as $x) $r[] = $x['d'][$i] ?? 0; $rows[] = $r; }
    return '<div class="chx" role="img" aria-label="' . ch_h($opt['label'] ?? 'نمودار خطی') . '" data-ch="' . ch_h(json_encode($data, JSON_UNESCAPED_UNICODE)) . '" style="min-height:' . $data['h'] . 'px"></div>'
        . ch_table(array_merge(['تاریخ'], array_column($s, 'n')), $rows);
}

/**
 * نمودار دایره‌ای / دونات.
 * $items = [['label' => 'ویجت', 'value' => 12], ...]  — بیش از ۶ بخش در «سایر» جمع می‌شود.
 * $opt: unit، center (متن زیر عدد وسط دونات)، empty
 */
function ch_pie(array $items, array $opt = [], $donut = false)
{
    $pal = ch_palette();
    $clean = [];
    foreach ($items as $it) { $v = (float)($it['value'] ?? 0); if ($v > 0) $clean[] = ['l' => (string)($it['label'] ?? ''), 'v' => round($v, 2), 'c' => (string)($it['color'] ?? '')]; }
    // رنگ به خود دسته تعلق دارد (ترتیب ورودی)، نه به رتبه
    foreach ($clean as $i => &$c) if ($c['c'] === '') $c['c'] = $pal[$i % count($pal)];
    unset($c);
    usort($clean, fn($a, $b) => $b['v'] <=> $a['v']);
    if (count($clean) > 6) {
        $rest = array_slice($clean, 5);
        $clean = array_slice($clean, 0, 5);
        $clean[] = ['l' => 'سایر', 'v' => array_sum(array_column($rest, 'v')), 'c' => '#9aa3af'];
    }
    $data = ['t' => $donut ? 'donut' : 'pie', 'i' => $clean, 'u' => (string)($opt['unit'] ?? ''), 'ct' => (string)($opt['center'] ?? 'مجموع'), 'h' => (int)($opt['height'] ?? 190), 'e' => (string)($opt['empty'] ?? 'هنوز داده‌ای برای نمایش نیست.')];
    $rows = [];
    foreach ($clean as $c) $rows[] = [$c['l'], $c['v']];
    return '<div class="chx" role="img" aria-label="' . ch_h($opt['label'] ?? ($donut ? 'نمودار دونات' : 'نمودار دایره‌ای')) . '" data-ch="' . ch_h(json_encode($data, JSON_UNESCAPED_UNICODE)) . '" style="min-height:' . $data['h'] . 'px"></div>'
        . ch_table(['بخش', 'مقدار'], $rows);
}

function ch_donut(array $items, array $opt = []) { return ch_pie($items, $opt, true); }

/** کارت نمودار آماده (عنوان + زیرعنوان + نمودار) */
function ch_card($title, $chart_html, $sub = '', $extra_class = '')
{
    return '<div class="chx-card ' . ch_h($extra_class) . '"><div class="chx-hd"><b>' . $title . '</b>' . ($sub !== '' ? '<span>' . $sub . '</span>' : '') . '</div>' . $chart_html . '</div>';
}

/**
 * CSS افکت لینک‌ها/دکمه‌ها + CSS و اسکریپت نمودارها. یک بار در <head> چاپ شود.
 * همه قواعد افکت با :where() نوشته شده‌اند (وزن صفر) تا استایل‌های اختصاصی صفحه‌ها همیشه غالب بمانند.
 */
function ui_fx_css()
{
    static $done = false;
    if ($done) return '';
    $done = true;
    return <<<'HTML'
<style id="ui-fx">
:where(a,button,.btn,summary,[role=button],input[type=submit],input[type=button],label.btn){transition:color .18s ease,background-color .18s ease,border-color .18s ease,box-shadow .2s ease,translate .18s ease,filter .18s ease,opacity .18s ease}
:where(a[href],button:not(:disabled),.btn,[role=button],input[type=submit]:not(:disabled),input[type=button]:not(:disabled)):hover{translate:0 -1px}
:where(a[href],button,.btn,[role=button],input[type=submit],input[type=button]):active{translate:0 0;filter:brightness(.97)}
:where(.btn,button[type=submit],input[type=submit],a[class*=btn]):not(:disabled):hover{filter:brightness(1.06);box-shadow:0 4px 14px rgba(15,23,42,.14)}
:where(a[href][class*=card],a[href][class*=tile],a[href][class*=box],a[href][class*=item-card],a.stat,a.plan):hover{translate:0 -2px;box-shadow:0 10px 24px rgba(15,23,42,.12)}
:where(a[href]:not([class])):hover{text-decoration:underline;text-underline-offset:3px}
:where(.nav-item,.sidebar a,.side a,nav a,.awt a,.kbt a,.tabs a,.tab a):hover{translate:-2px 0}
:where(summary):hover{filter:brightness(.92)}
:where(a[href],button,.btn,summary,[role=button]):focus-visible{outline:2px solid #2a78d6;outline-offset:2px}
@media (prefers-reduced-motion:reduce){:where(*):hover,:where(*):active{translate:none!important}}
/* نمودارها */
.chx{position:relative;width:100%;direction:ltr;font-family:inherit;color:#334155}
.chx svg{display:block;width:100%;overflow:visible}
.chx .chx-empty{display:flex;align-items:center;justify-content:center;height:100%;min-height:inherit;color:#94a3b8;font-size:13px;direction:rtl}
.chx-tip{position:absolute;pointer-events:none;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.14);padding:7px 10px;font-size:12px;line-height:1.8;direction:rtl;text-align:right;white-space:nowrap;z-index:5;opacity:0;transition:opacity .12s;color:#0f172a}
.chx-tip b{font-weight:700}.chx-tip i{display:inline-block;width:9px;height:9px;border-radius:3px;margin-left:6px;vertical-align:middle}
.chx-tip .m{color:#64748b;font-size:11px}
.chx-lg{display:flex;flex-wrap:wrap;gap:6px 14px;direction:rtl;font-size:12px;color:#475569;margin:0 0 8px}
.chx-lg span{display:inline-flex;align-items:center;gap:6px}.chx-lg i{width:10px;height:10px;border-radius:3px;display:inline-block}
.chx-pw{display:flex;align-items:center;gap:16px;direction:rtl;flex-wrap:wrap}
.chx-pw .pv{flex:0 0 auto}
.chx-pw .pl{flex:1 1 150px;min-width:140px;display:flex;flex-direction:column;gap:6px;font-size:12.5px}
.chx-pw .pl div{display:flex;align-items:center;gap:8px;color:#334155;padding:3px 6px;border-radius:8px;cursor:default}
.chx-pw .pl div:hover,.chx-pw .pl div.on{background:#f1f5f9}
.chx-pw .pl i{width:10px;height:10px;border-radius:3px;flex:0 0 10px}
.chx-pw .pl span{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chx-pw .pl em{font-style:normal;color:#0f172a;font-weight:700}
.chx-pw .pl small{color:#64748b;min-width:38px;text-align:left}
.chx-sr{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;white-space:nowrap!important;border:0!important;padding:0!important}
.chx-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px 12px;min-width:0}
.chx-card .chx-hd{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.chx-card .chx-hd b{font-size:14px;color:#0f172a}.chx-card .chx-hd span{font-size:12px;color:#64748b}
.chx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin:0 0 18px}
.chx-grid .wide{grid-column:span 2}
@media (max-width:760px){.chx-grid .wide{grid-column:auto}}
</style>
<script>
(function(){
if(window.__chx)return;window.__chx=1;
var NS='http://www.w3.org/2000/svg';
function fa(n){try{return Number(n).toLocaleString('fa-IR',{maximumFractionDigits:2});}catch(e){return String(n);}}
function short(n){var a=Math.abs(n);if(a>=1e9)return fa(+(n/1e9).toFixed(1))+' میلیارد';if(a>=1e6)return fa(+(n/1e6).toFixed(1))+' میلیون';if(a>=1e4)return fa(+(n/1e3).toFixed(0))+' هزار';return fa(n);}
function el(t,a,p){var e=document.createElementNS(NS,t);for(var k in a)e.setAttribute(k,a[k]);if(p)p.appendChild(e);return e;}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function nice(m){if(m<=0)return 1;var e=Math.pow(10,Math.floor(Math.log10(m))),f=m/e;return (f<=1?1:f<=2?2:f<=5?5:10)*e;}
function tip(box){var t=box.querySelector('.chx-tip');if(!t){t=document.createElement('div');t.className='chx-tip';box.appendChild(t);}return t;}
function place(box,t,x,y){var w=box.clientWidth,tw=t.offsetWidth,th=t.offsetHeight;var l=x+14;if(l+tw>w)l=x-tw-14;if(l<0)l=0;var tp=y-th-10;if(tp<0)tp=y+14;t.style.left=l+'px';t.style.top=tp+'px';t.style.opacity=1;}
function line(box,d){
  var W=Math.max(box.clientWidth,240),H=d.h,pl=8,pr=8,pt=10,pb=26;
  var n=d.l.length,max=0,any=false;d.s.forEach(function(s){s.d.forEach(function(v){if(v>max)max=v;if(v)any=true;});});
  box.innerHTML='';
  if(!n||!any){box.innerHTML='<div class="chx-empty">'+esc(d.e)+'</div>';return;}
  if(d.s.length>1){var lg=document.createElement('div');lg.className='chx-lg';d.s.forEach(function(s){lg.insertAdjacentHTML('beforeend','<span><i style="background:'+s.c+'"></i>'+esc(s.n)+'</span>');});box.appendChild(lg);}
  var intd=d.s.every(function(s){return s.d.every(function(v){return Math.round(v)===v;});});
  var step=nice(max*1.05/4);if(intd&&step<1)step=1;var top=Math.ceil(max*1.05/step)*step;if(top<=0)top=step;var ticks=Math.round(top/step);
  var lblw=0;for(var q=0;q<=ticks;q++){lblw=Math.max(lblw,short(top*q/ticks).length);}pl=Math.max(26,lblw*6.2+10);
  var svg=el('svg',{width:W,height:H,viewBox:'0 0 '+W+' '+H},box);
  var iw=W-pl-pr,ih=H-pt-pb;
  function X(i){return pl+(n===1?iw/2:i*iw/(n-1));}function Y(v){return pt+ih-(v/top)*ih;}
  for(var k=0;k<=ticks;k++){var v=top*k/ticks,y=Y(v);el('line',{x1:pl,x2:W-pr,y1:y,y2:y,stroke:k?'#eef2f7':'#cbd5e1','stroke-width':1},svg);var tx=el('text',{x:pl-6,y:y+4,'text-anchor':'end','font-size':11,fill:'#94a3b8'},svg);tx.textContent=short(v);}
  var xs=Math.max(1,Math.ceil(n/Math.max(2,Math.floor(iw/64))));
  for(var i=0;i<n;i+=xs){var t=el('text',{x:X(i),y:H-8,'text-anchor':'middle','font-size':11,fill:'#94a3b8'},svg);t.textContent=d.l[i];}
  d.s.forEach(function(s,si){
    var p='';s.d.forEach(function(v,i){p+=(i?'L':'M')+X(i).toFixed(1)+' '+Y(v).toFixed(1);});
    if(d.s.length===1){el('path',{d:p+'L'+X(n-1).toFixed(1)+' '+Y(0)+'L'+X(0).toFixed(1)+' '+Y(0)+'Z',fill:s.c,'fill-opacity':.1,stroke:'none'},svg);}
    el('path',{d:p,fill:'none',stroke:s.c,'stroke-width':2,'stroke-linejoin':'round','stroke-linecap':'round'},svg);
    var li=n-1;el('circle',{cx:X(li),cy:Y(s.d[li]||0),r:4,fill:s.c,stroke:'#fff','stroke-width':2},svg);
  });
  var cross=el('line',{y1:pt,y2:pt+ih,stroke:'#94a3b8','stroke-width':1,'stroke-dasharray':'3 3',opacity:0},svg);
  var dots=d.s.map(function(s){return el('circle',{r:4.5,fill:s.c,stroke:'#fff','stroke-width':2,opacity:0},svg);});
  var hit=el('rect',{x:pl,y:0,width:iw,height:H,fill:'transparent'},svg);var T=tip(box);
  function mv(ev){var r=svg.getBoundingClientRect();var x=(ev.clientX-r.left);var i=n===1?0:Math.round((x-pl)/(iw/(n-1)));if(i<0)i=0;if(i>n-1)i=n-1;
    cross.setAttribute('x1',X(i));cross.setAttribute('x2',X(i));cross.setAttribute('opacity',1);
    var h='<div class="m">'+esc(d.l[i])+'</div>';d.s.forEach(function(s,si){var v=s.d[i]||0;dots[si].setAttribute('cx',X(i));dots[si].setAttribute('cy',Y(v));dots[si].setAttribute('opacity',1);h+='<div><i style="background:'+s.c+'"></i>'+(s.n?esc(s.n)+': ':'')+'<b>'+fa(v)+'</b> '+esc(d.u)+'</div>';});
    T.innerHTML=h;place(box,T,X(i)*(r.width/W),(Math.min.apply(null,d.s.map(function(s){return Y(s.d[i]||0);})))+(svg.getBoundingClientRect().top-box.getBoundingClientRect().top));}
  function out(){cross.setAttribute('opacity',0);dots.forEach(function(c){c.setAttribute('opacity',0);});T.style.opacity=0;}
  hit.addEventListener('pointermove',mv);hit.addEventListener('pointerdown',mv);hit.addEventListener('pointerleave',out);
}
function pie(box,d){
  box.innerHTML='';var it=d.i,tot=0;it.forEach(function(x){tot+=x.v;});
  if(!it.length||tot<=0){box.innerHTML='<div class="chx-empty">'+esc(d.e)+'</div>';return;}
  var S=Math.min(d.h,Math.max(140,Math.min(box.clientWidth-10,d.h))),R=S/2-2,cx=S/2,cy=S/2,ri=d.t==='donut'?R*0.62:0;
  var wrap=document.createElement('div');wrap.className='chx-pw';box.appendChild(wrap);
  var pv=document.createElement('div');pv.className='pv';wrap.appendChild(pv);
  var svg=el('svg',{width:S,height:S,viewBox:'0 0 '+S+' '+S},pv);svg.style.width=S+'px';
  var lg=document.createElement('div');lg.className='pl';wrap.appendChild(lg);
  var T=tip(box),paths=[],a0=-Math.PI/2;
  function pt(a,r){return (cx+r*Math.cos(a)).toFixed(2)+' '+(cy+r*Math.sin(a)).toFixed(2);}
  it.forEach(function(x,idx){
    var a1=a0+x.v/tot*Math.PI*2,big=(a1-a0)>Math.PI?1:0,p;
    if(it.length===1){p=ri?('M'+pt(0,R)+'A'+R+' '+R+' 0 1 1 '+pt(Math.PI,R)+'A'+R+' '+R+' 0 1 1 '+pt(0,R)+'M'+pt(0,ri)+'A'+ri+' '+ri+' 0 1 0 '+pt(Math.PI,ri)+'A'+ri+' '+ri+' 0 1 0 '+pt(0,ri)+'Z'):('M'+pt(0,R)+'A'+R+' '+R+' 0 1 1 '+pt(Math.PI,R)+'A'+R+' '+R+' 0 1 1 '+pt(0,R)+'Z');}
    else if(ri){p='M'+pt(a0,R)+'A'+R+' '+R+' 0 '+big+' 1 '+pt(a1,R)+'L'+pt(a1,ri)+'A'+ri+' '+ri+' 0 '+big+' 0 '+pt(a0,ri)+'Z';}
    else{p='M'+cx+' '+cy+'L'+pt(a0,R)+'A'+R+' '+R+' 0 '+big+' 1 '+pt(a1,R)+'Z';}
    var e=el('path',{d:p,fill:x.c,stroke:'#fff','stroke-width':2,'stroke-linejoin':'round','fill-rule':'evenodd'},svg);e.style.transition='opacity .15s';paths.push(e);
    var pc=Math.round(x.v/tot*1000)/10;
    var row=document.createElement('div');row.innerHTML='<i style="background:'+x.c+'"></i><span title="'+esc(x.l)+'">'+esc(x.l)+'</span><em>'+short(x.v)+'</em><small>'+fa(pc)+'٪</small>';lg.appendChild(row);
    function on(ev){paths.forEach(function(q,j){q.style.opacity=j===idx?1:.45;});row.className='on';T.innerHTML='<div><i style="background:'+x.c+'"></i><b>'+esc(x.l)+'</b></div><div>'+fa(x.v)+' '+esc(d.u)+' <span class="m">('+fa(pc)+'٪)</span></div>';var b=box.getBoundingClientRect();place(box,T,(ev.clientX||b.left)-b.left,(ev.clientY||b.top)-b.top);}
    function off(){paths.forEach(function(q){q.style.opacity=1;});row.className='';T.style.opacity=0;}
    e.addEventListener('pointermove',on);e.addEventListener('pointerdown',on);e.addEventListener('pointerleave',off);row.addEventListener('pointerenter',function(ev){on(ev);});row.addEventListener('pointerleave',off);
    a0=a1;
  });
  if(ri){var t1=el('text',{x:cx,y:cy+2,'text-anchor':'middle','font-size':Math.max(14,Math.round(R*0.3)),'font-weight':700,fill:'#0f172a'},svg);t1.textContent=short(tot);var t2=el('text',{x:cx,y:cy+Math.max(16,R*0.26),'text-anchor':'middle','font-size':11,fill:'#64748b'},svg);t2.textContent=d.ct;}
}
function draw(box){var d;try{d=JSON.parse(box.getAttribute('data-ch'));}catch(e){return;}box.__w=box.clientWidth;try{if(d.t==='line')line(box,d);else pie(box,d);}catch(e){console.error(e);}}
function scan(root){(root||document).querySelectorAll('.chx[data-ch]').forEach(function(b){if(!b.__drawn){b.__drawn=1;draw(b);if(window.ResizeObserver){new ResizeObserver(function(){if(Math.abs(b.clientWidth-(b.__w||0))>4)draw(b);}).observe(b);}}});}
function start(){scan();if(window.MutationObserver){new MutationObserver(function(){scan();}).observe(document.body,{childList:true,subtree:true});}}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
</script>
HTML;
}

/**
 * حذف دومرحله‌ای (برای منابع و فایل‌های مهم مثل پایگاه دانش)
 * استفاده: فرم حذف با data-del2-title="..." و data-del2-info="..." و یک فیلد مخفی name="confirm_del".
 *   مرحله ۱: پنجره هشدار (چه چیزی حذف می‌شود) ← «ادامه»
 *   مرحله ۲: نوشتن کلمه «حذف» ← «حذف برای همیشه»
 * سمت سرور هم با ui_del2_ok() بررسی می‌شود تا بدون این دو مرحله حذفی انجام نشود.
 */
function ui_del2_ok()
{
    $v = trim(str_replace(["\u{200C}", ' '], '', (string)($_POST['confirm_del'] ?? '')));
    return $v === 'حذف';
}

function ui_del2_assets()
{
    static $done = false;
    if ($done) return '';
    $done = true;
    return <<<'HTML'
<style>
.d2-bg{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px}
.d2-box{background:#fff;border-radius:16px;max-width:430px;width:100%;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.25);direction:rtl;text-align:right;font-size:13.5px;line-height:1.9;color:#0f172a}
.d2-box h4{margin:0 0 10px;font-size:15.5px;color:#b91c1c}
.d2-st{font-size:11.5px;color:#64748b;margin-bottom:6px}
.d2-warn{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 12px;color:#7f1d1d;font-size:12.5px;margin-bottom:12px;white-space:pre-line}
.d2-box input{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:9px 12px;font-family:inherit;font-size:14px;margin:6px 0 12px;box-sizing:border-box}
.d2-box input:focus{outline:none;border-color:#dc2626}
.d2-act{display:flex;gap:8px;flex-wrap:wrap}.d2-act button{flex:1;min-width:120px;border:0;border-radius:10px;padding:10px 12px;font-family:inherit;font-size:13.5px;font-weight:700;cursor:pointer}
.d2-go{background:#dc2626;color:#fff}.d2-go[disabled]{opacity:.45;cursor:not-allowed}.d2-no{background:#f1f5f9;color:#334155}
</style>
<script>
(function(){
  if (window.__del2) return; window.__del2 = 1;
  function esc(s){ return String(s || '').replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function open(form){
    var title = form.getAttribute('data-del2-title') || 'این مورد', info = form.getAttribute('data-del2-info') || '';
    var bg = document.createElement('div'); bg.className = 'd2-bg';
    bg.innerHTML = '<div class="d2-box" role="dialog" aria-modal="true"><div class="d2-st">مرحله ۱ از ۲</div><h4>🗑 حذف «' + esc(title) + '»</h4>'
      + '<div class="d2-warn">' + esc(info || 'این مورد برای همیشه حذف می‌شود.') + '\nاین کار قابل بازگشت نیست.</div>'
      + '<div class="d2-act"><button type="button" class="d2-go" data-a="next">ادامه حذف</button><button type="button" class="d2-no" data-a="no">انصراف</button></div></div>';
    document.body.appendChild(bg);
    var box = bg.querySelector('.d2-box');
    function close(){ bg.remove(); document.removeEventListener('keydown', key); }
    function key(e){ if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', key);
    bg.addEventListener('click', function(e){ if (e.target === bg) close(); });
    box.querySelector('[data-a=no]').onclick = close;
    box.querySelector('[data-a=next]').onclick = function(){
      box.innerHTML = '<div class="d2-st">مرحله ۲ از ۲ — تأیید نهایی</div><h4>⚠️ مطمئن هستید؟</h4>'
        + '<div>برای حذف همیشگی «<b>' + esc(title) + '</b>»، کلمه <b style="color:#b91c1c">حذف</b> را بنویسید:</div>'
        + '<input type="text" autocomplete="off" placeholder="حذف">'
        + '<div class="d2-act"><button type="button" class="d2-go" data-a="go" disabled>🗑 حذف برای همیشه</button><button type="button" class="d2-no" data-a="no">انصراف</button></div>';
      var inp = box.querySelector('input'), go = box.querySelector('[data-a=go]');
      inp.focus();
      inp.oninput = function(){ go.disabled = inp.value.replace(/[\s‌]/g, '') !== 'حذف'; };
      inp.onkeydown = function(e){ if (e.key === 'Enter' && !go.disabled) go.click(); };
      box.querySelector('[data-a=no]').onclick = close;
      go.onclick = function(){
        var f = form.querySelector('[name=confirm_del]');
        if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = 'confirm_del'; form.appendChild(f); }
        f.value = 'حذف'; go.disabled = true; go.textContent = '⏳ در حال حذف…';
        form.__del2ok = true; close();
        // requestSubmit: ارسال عادی فرم (با بارگذاری بدون رفرش پنل)؛ مرورگرهای قدیمی: submit
        try { if (form.requestSubmit) form.requestSubmit(); else HTMLFormElement.prototype.submit.call(form); } catch (x) { HTMLFormElement.prototype.submit.call(form); }
        setTimeout(function(){ form.__del2ok = false; f.value = ''; }, 1500);
      };
    };
  }
  document.addEventListener('submit', function(e){
    var f = e.target;
    if (!f || !f.hasAttribute || !f.hasAttribute('data-del2-title') || f.__del2ok) return;
    e.preventDefault(); e.stopPropagation(); open(f);
  }, true);
})();
</script>
HTML;
}

/**
 * منوی موبایل پنل‌ها (مدیر و کاربر)
 *  - دکمه همبرگری در نوار بالا (ui_mobile_burger) + کشوی کناری با همان منوی سایدبار
 *  - نوار پایین ثابت با میان‌برهای پرکاربرد + دکمه «منو»
 *  - بستن با لمس پس‌زمینه، Esc، انتخاب گزینه یا کشیدن انگشت به راست
 *  - جدول‌های پهن خودکار قابل اسکرول افقی؛ فیلدها ۱۶px (بدون بزرگ‌نمایی خودکار آیفون)
 * $items: [['href' => 'index.php', 'icon' => '🏠', 'label' => 'خانه', 'badge' => 0], ...] (حداکثر ۴ مورد)
 */
function ui_mobile_burger()
{
    return '<button type="button" class="mnav-btn" id="mnavBtn" aria-label="باز کردن منو" aria-controls="mnavSide" aria-expanded="false">'
         . '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>';
}

function ui_mobile_nav(array $items)
{
    static $done = false;
    if ($done) return '';
    $done = true;
    $cur = basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $h = '<nav class="mnav-bar" aria-label="دسترسی سریع">';
    foreach (array_slice($items, 0, 4) as $it) {
        $on = basename((string)parse_url($it['href'], PHP_URL_PATH)) === $cur;
        $b = (int)($it['badge'] ?? 0);
        $h .= '<a href="' . htmlspecialchars($it['href'], ENT_QUOTES, 'UTF-8') . '" class="' . ($on ? 'on' : '') . '"' . ($on ? ' aria-current="page"' : '') . '><span class="i">' . $it['icon']
            . ($b > 0 ? '<b>' . ($b > 99 ? '99+' : $b) . '</b>' : '') . '</span><span class="t">' . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') . '</span></a>';
    }
    $h .= '<button type="button" class="mnav-more" aria-controls="mnavSide"><span class="i"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></span><span class="t">منو</span></button></nav>';
    $h .= '<div class="mnav-ov" id="mnavOv" hidden></div>';
    return $h . <<<'HTML'
<style>
.mnav-btn,.mnav-bar,.mnav-close{display:none}
@media (max-width:900px){
  .mnav-btn{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;border:1px solid #e2e8f0;background:#fff;color:#0f172a;cursor:pointer;flex:0 0 auto;margin-left:10px;-webkit-tap-highlight-color:transparent}
  .mnav-btn:active{transform:scale(.95)}
  .topbar-header{padding:0 12px!important;gap:8px}
  .topbar-header h1,.topbar-header .breadcrumb{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1 1 auto}
  .topbar-header .breadcrumb>span{display:none}
  .topbar-credit-badge{padding:5px 9px!important}
  aside.sidebar,div.sidebar{display:flex!important;width:min(300px,86vw)!important;height:100%!important;height:100dvh!important;transform:translateX(105%);transition:transform .25s ease;z-index:1002!important;visibility:hidden;padding-bottom:env(safe-area-inset-bottom)}
  body.mnav-open aside.sidebar,body.mnav-open div.sidebar{transform:none;visibility:visible;box-shadow:-12px 0 40px rgba(15,23,42,.25)}
  body.mnav-open{overflow:hidden}
  .mnav-ov{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1001;backdrop-filter:blur(2px)}
  .mnav-close{display:flex;align-items:center;justify-content:center;position:sticky;top:8px;margin:8px 8px -46px auto;width:38px;height:38px;border-radius:50%;border:0;background:#f1f5f9;color:#334155;font-size:20px;cursor:pointer;z-index:2;flex:0 0 auto}
  .sidebar a,.sidebar .nav-item{min-height:44px}
  .mnav-bar{display:flex;position:fixed;bottom:0;right:0;left:0;z-index:900;background:rgba(255,255,255,.97);border-top:1px solid #e2e8f0;box-shadow:0 -4px 18px rgba(15,23,42,.06);padding:4px 4px calc(4px + env(safe-area-inset-bottom));backdrop-filter:blur(10px)}
  .mnav-bar a,.mnav-bar button{flex:1 1 0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;min-height:52px;border:0;background:none;color:#64748b;font:inherit;font-size:11px;text-decoration:none!important;border-radius:12px;cursor:pointer;-webkit-tap-highlight-color:transparent;padding:0}
  .mnav-bar .i{position:relative;font-size:20px;line-height:1;display:flex}
  .mnav-bar .i b{position:absolute;top:-6px;left:-12px;background:#dc2626;color:#fff;border-radius:10px;min-width:17px;height:17px;font-size:10px;line-height:17px;text-align:center;padding:0 4px;font-weight:700}
  .mnav-bar .on{color:#1d4ed8;background:#eff6ff;font-weight:700}
  .main{padding-bottom:calc(76px + env(safe-area-inset-bottom))!important}
  .pg-toast,.rmt{bottom:calc(80px + env(safe-area-inset-bottom))!important}
  input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]),select,textarea{font-size:16px!important}
  .btn,.btn-sm,button.btn{min-height:38px}
  .mnav-tw{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
  img,video,iframe{max-width:100%}
}
@media (prefers-reduced-motion:reduce){aside.sidebar,div.sidebar{transition:none}}
</style>
<script>
(function(){
  if (window.__mnav) return; window.__mnav = 1;
  function q(s){ return document.querySelector(s); }
  function side(){ return q('aside.sidebar') || q('div.sidebar'); }
  var ov = q('#mnavOv'), last = null;
  function set(open){
    var s = side(); if (!s) return;
    document.body.classList.toggle('mnav-open', open);
    if (ov) ov.hidden = !open;
    var b = q('#mnavBtn'); if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { last = document.activeElement; var c = s.querySelector('.mnav-close'); if (c) c.focus(); var a = s.querySelector('.active,.on'); if (a && a.scrollIntoView) try { a.scrollIntoView({ block: 'center' }); } catch (e) {} }
    else if (last && last.focus) { try { last.focus(); } catch (e) {} }
  }
  function init(){
    var s = side(); if (!s) return;
    s.id = s.id || 'mnavSide';
    if (!s.querySelector('.mnav-close')) { var c = document.createElement('button'); c.type = 'button'; c.className = 'mnav-close'; c.setAttribute('aria-label', 'بستن منو'); c.textContent = '✕'; c.onclick = function(){ set(false); }; s.insertBefore(c, s.firstChild); }
    var b = q('#mnavBtn'); if (b) b.onclick = function(){ set(!document.body.classList.contains('mnav-open')); };
    var m = q('.mnav-bar .mnav-more'); if (m) m.onclick = function(){ set(true); };
    if (ov) ov.onclick = function(){ set(false); };
    s.addEventListener('click', function(e){ var a = e.target.closest && e.target.closest('a[href]'); if (a && window.innerWidth <= 900) set(false); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && document.body.classList.contains('mnav-open')) set(false); });
    // کشیدن انگشت: از لبه راست به چپ = باز، روی منو به راست = بسته
    var sx = null, sy = 0, fromEdge = false;
    document.addEventListener('touchstart', function(e){ if (window.innerWidth > 900 || e.touches.length !== 1) return; sx = e.touches[0].clientX; sy = e.touches[0].clientY; fromEdge = sx > window.innerWidth - 24; }, { passive: true });
    document.addEventListener('touchend', function(e){
      if (sx === null) return; var t = e.changedTouches[0], dx = t.clientX - sx, dy = Math.abs(t.clientY - sy); var open = document.body.classList.contains('mnav-open');
      if (dy < 60) { if (!open && fromEdge && dx < -60) set(true); else if (open && dx > 70) set(false); }
      sx = null;
    }, { passive: true });
    window.addEventListener('resize', function(){ if (window.innerWidth > 900 && document.body.classList.contains('mnav-open')) set(false); });
    wrap(document);
    if (window.MutationObserver) { var mo = new MutationObserver(function(){ wrap(document); }); var r = q('#pgc') || q('.main'); if (r) mo.observe(r, { childList: true, subtree: true }); }
  }
  // جدول‌های پهن: اسکرول افقی به‌جای بیرون زدن از صفحه
  function wrap(root){
    Array.prototype.forEach.call(root.querySelectorAll('.main table'), function(t){
      if (t.__mw) return; t.__mw = 1;
      var p = t.parentElement, cs = p ? getComputedStyle(p) : null;
      if (!p || /auto|scroll/.test(cs.overflowX) || p.classList.contains('table-wrap') || p.classList.contains('table-responsive')) return;
      var w = document.createElement('div'); w.className = 'mnav-tw'; p.insertBefore(w, t); w.appendChild(t);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>
HTML;
}
