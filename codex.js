(()=>{
  const reducir=matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fondo=document.createElement('div');fondo.className='codex-bg';fondo.setAttribute('aria-hidden','true');
  for(let i=0;i<5;i++)fondo.appendChild(document.createElement('i'));
  if(!reducir){
    for(let i=0;i<14;i++){
      const b=document.createElement('b'),t=6+Math.random()*16;
      b.style.cssText=`left:${Math.random()*100}%;width:${t}px;height:${t}px;animation-duration:${14+Math.random()*18}s;animation-delay:${-Math.random()*20}s;--dx:${(Math.random()-.5)*160}px`;
      fondo.appendChild(b);
    }
  }
  document.body.prepend(fondo);
  if(reducir)return;
  // Resplandor que sigue al cursor
  const luz=document.createElement('div');luz.className='codex-brillo';document.body.appendChild(luz);
  addEventListener('pointermove',e=>{luz.style.left=e.clientX+'px';luz.style.top=e.clientY+'px'},{passive:true});
  // Aparición al hacer scroll
  const io=new IntersectionObserver(es=>es.forEach(x=>{if(x.isIntersecting){x.target.classList.add('cx-visto');io.unobserve(x.target)}}),{threshold:0,rootMargin:'0px 0px -40px 0px'});
  const animables=[];
  document.querySelectorAll('.card,.owner-card,.promo-item,.product-item').forEach((el,i)=>{
    if(el.offsetHeight>innerHeight*0.8||el.querySelector('table,form,input,select'))return; // nunca ocultar tablas ni formularios
    el.classList.add('cx-oculto');el.style.transitionDelay=Math.min(i%8,7)*60+'ms';io.observe(el);animables.push(el);
  });
  setTimeout(()=>animables.forEach(el=>el.classList.add('cx-visto')),2500); // red de seguridad
  // Onda al tocar botones
  document.addEventListener('click',e=>{
    const b=e.target.closest('.btn');if(!b)return;
    const r=b.getBoundingClientRect(),d=Math.max(r.width,r.height),o=document.createElement('span');
    o.className='cx-onda';o.style.cssText=`width:${d}px;height:${d}px;left:${e.clientX-r.left-d/2}px;top:${e.clientY-r.top-d/2}px`;
    b.appendChild(o);setTimeout(()=>o.remove(),600);
  });
})();

/* ---- Cantidad con botones +/- y carrito sin recargar la página ---- */
(()=>{
  document.addEventListener('click',e=>{
    const b=e.target.closest('[data-qty]');if(!b)return;
    const i=b.closest('.cx-qty').querySelector('input');
    const min=+i.min||1,max=+i.max||999;
    i.value=Math.max(min,Math.min(max,(parseInt(i.value)||min)+(+b.dataset.qty)));
    i.classList.remove('cx-pop');void i.offsetWidth;i.classList.add('cx-pop');
  });
  const toast=(t,mal)=>{
    if(!t)return;
    let c=document.querySelector('.cx-toasts');
    if(!c){c=document.createElement('div');c.className='cx-toasts';document.body.appendChild(c);}
    const n=document.createElement('div');n.className='cx-toast'+(mal?' cx-toast-mal':'');n.textContent=t;c.appendChild(n);
    setTimeout(()=>{n.classList.add('cx-toast-sale');setTimeout(()=>n.remove(),400)},2600);
  };
  const cfg=window.CODEX_AJAX;if(!cfg)return;
  document.addEventListener('submit',async e=>{
    const f=e.target;if(e.defaultPrevented||!(f instanceof HTMLFormElement))return;
    const a=f.querySelector('[name="accion"]');if(!a||!cfg.acciones.includes(a.value))return;
    e.preventDefault();
    const botones=[...f.querySelectorAll('button')];botones.forEach(b=>b.disabled=true);
    try{
      const r=await fetch(f.getAttribute('action')||location.href,{method:'POST',body:new FormData(f),credentials:'same-origin'});
      if(!r.ok)throw new Error('http');
      const doc=new DOMParser().parseFromString(await r.text(),'text/html');
      let cambio=false;
      cfg.swap.forEach(sel=>{
        const cur=document.querySelector(sel),nue=doc.querySelector(sel);
        if(cur&&nue){cur.innerHTML=nue.innerHTML;cur.classList.remove('cx-actualizado');void cur.offsetWidth;cur.classList.add('cx-actualizado');cambio=true;}
      });
      if(!cambio)throw new Error('sin cambios');
      if(cfg.onDone)cfg.onDone(doc,f);
      const m=cfg.mensaje?cfg.mensaje(doc):'';
      toast(m||'Carrito actualizado');
      const add=f.querySelector('.cx-add');if(add){add.classList.add('cx-ok');setTimeout(()=>add.classList.remove('cx-ok'),700);}
    }catch(err){toast('No se pudo completar la acción. Reintentá.',true);}
    finally{botones.forEach(b=>b.disabled=false);}
  });
})();

/* ---- Escáner con la cámara del celular (códigos de barras y QR) ---- */
window.codexEscaner=(()=>{
  const LIB='https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
  let stream=null,ciclo=0,h5=null,panelActual=null;
  const cargarLib=()=>window.Html5Qrcode?Promise.resolve():new Promise((ok,mal)=>{
    const s=document.createElement('script');s.src=LIB;s.onload=ok;s.onerror=()=>mal(new Error('lib'));document.head.appendChild(s);
  });
  async function parar(){
    ciclo++;
    if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}
    if(h5){try{await h5.stop();h5.clear();}catch(e){}h5=null;}
    if(panelActual){panelActual.innerHTML='';panelActual.hidden=true;}
  }
  async function vivo(panel,onCode){
    panelActual=panel;panel.hidden=false;
    panel.innerHTML='<div class="cx-visor"><video class="camera-preview" playsinline muted></video><div id="cx-h5"></div></div><button type="button" class="btn btn-outline-secondary mt-2" data-cx-cerrar>Cerrar cámara</button>';
    panel.querySelector('[data-cx-cerrar]').onclick=parar;
    const id=++ciclo;
    if('BarcodeDetector' in window){
      const video=panel.querySelector('video');
      stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
      video.srcObject=stream;await video.play();
      const det=new BarcodeDetector();
      const buscar=async()=>{
        if(id!==ciclo)return;
        try{const r=await det.detect(video);if(r.length){const c=r[0].rawValue;await parar();onCode(c);return;}}catch(e){}
        requestAnimationFrame(buscar);
      };
      buscar();
    }else{
      await cargarLib();
      panel.querySelector('video').remove();
      h5=new Html5Qrcode('cx-h5');
      await h5.start({facingMode:'environment'},{fps:10,qrbox:{width:260,height:150}},async c=>{await parar();onCode(c);});
    }
  }
  // Alternativa que funciona aunque la página no sea HTTPS: abre la cámara nativa y lee la foto
  function foto(onCode,estado){
    const i=document.createElement('input');i.type='file';i.accept='image/*';i.setAttribute('capture','environment');
    i.onchange=async()=>{
      const f=i.files&&i.files[0];if(!f)return;
      estado('Leyendo código...');
      try{
        let code=null;
        if('BarcodeDetector' in window){
          const r=await new BarcodeDetector().detect(await createImageBitmap(f));
          code=r[0]&&r[0].rawValue;
        }
        if(!code){
          await cargarLib();
          const d=document.createElement('div');d.id='cx-tmp';d.style.display='none';document.body.appendChild(d);
          const h=new Html5Qrcode('cx-tmp');
          try{code=await h.scanFile(f,false);}finally{try{h.clear();}catch(e){}d.remove();}
        }
        if(code){estado('Código leído.');onCode(code);}else estado('No se pudo leer. Acercá el código y probá de nuevo.');
      }catch(e){estado('No se pudo leer el código. Probá con más luz o más cerca.');}
    };
    i.click();
  }
  async function abrir(o){
    const seguro=window.isSecureContext&&navigator.mediaDevices&&navigator.mediaDevices.getUserMedia;
    if(seguro){
      try{o.estado('Apuntá la cámara al código.');await vivo(o.panel,o.onCode);return;}
      catch(e){await parar();}
    }
    o.estado('Tomá una foto nítida del código.');
    foto(o.onCode,o.estado);
  }
  return {abrir,parar,foto};
})();
