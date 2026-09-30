document.addEventListener('DOMContentLoaded',()=>{
  const menu=document.querySelector('[data-menu]');
  if(menu) menu.addEventListener('click',()=>document.querySelector('#sidebar')?.classList.toggle('open'));

  const canvas=document.querySelector('#trendChart');
  if(canvas){
    const labels=JSON.parse(canvas.dataset.labels||'[]').map(v=>`${v} 月`);
    const values=JSON.parse(canvas.dataset.values||'[]').map(Number);
    if(window.Chart){
    new Chart(canvas,{type:'line',data:{labels,datasets:[{data:values,borderColor:'#155c42',backgroundColor:'rgba(21,92,66,.08)',fill:true,tension:.35,pointRadius:3,pointBackgroundColor:'#155c42'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},border:{display:false}},y:{beginAtZero:true,grid:{color:'#edf1ef'},border:{display:false}}}}});
    }else{
      const ratio=window.devicePixelRatio||1,w=canvas.clientWidth||700,h=canvas.clientHeight||250;
      canvas.width=w*ratio;canvas.height=h*ratio;const c=canvas.getContext('2d');c.scale(ratio,ratio);c.clearRect(0,0,w,h);
      const pad={l:38,r:16,t:20,b:28},max=Math.max(...values,1),step=(w-pad.l-pad.r)/Math.max(values.length-1,1);
      c.strokeStyle='#e5ebe8';c.fillStyle='#708078';c.font='10px sans-serif';
      for(let i=0;i<=4;i++){const y=pad.t+(h-pad.t-pad.b)*i/4;c.beginPath();c.moveTo(pad.l,y);c.lineTo(w-pad.r,y);c.stroke();c.fillText((max*(1-i/4)).toFixed(0),2,y+3)}
      if(values.length){c.beginPath();values.forEach((v,i)=>{const x=pad.l+i*step,y=pad.t+(h-pad.t-pad.b)*(1-v/max);i?c.lineTo(x,y):c.moveTo(x,y)});c.strokeStyle='#155c42';c.lineWidth=2.5;c.stroke();values.forEach((v,i)=>{const x=pad.l+i*step,y=pad.t+(h-pad.t-pad.b)*(1-v/max);c.beginPath();c.arc(x,y,3,0,Math.PI*2);c.fillStyle='#155c42';c.fill();c.fillStyle='#708078';c.fillText(labels[i]||'',x-8,h-8)})}
    }
  }

  const activity=document.querySelector('[data-activity-form]');
  if(activity){
    const source=activity.querySelector('[name=source_id]');
    const factor=activity.querySelector('[name=factor_id]');
    const validate=()=>{
      const su=source.selectedOptions[0]?.dataset.unit;
      [...factor.options].forEach(o=>{o.disabled=Boolean(o.value&&su&&o.dataset.unit!==su)});
      if(factor.selectedOptions[0]?.disabled) factor.value='';
    };
    source.addEventListener('change',validate); validate();
  }
});
