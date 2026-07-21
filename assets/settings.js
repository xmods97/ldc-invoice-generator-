(()=>{'use strict';
const app=document.querySelector('#ldc-company-settings-app');if(!app)return;
const form=app.querySelector('#ldc-company-form'),button=app.querySelector('#ldc-company-save'),notice=app.querySelector('#ldc-company-notice');
function show(message,error=false){notice.hidden=false;notice.textContent=message;notice.classList.toggle('error',error);setTimeout(()=>notice.hidden=true,5000)}
button.addEventListener('click',async()=>{button.disabled=true;button.textContent='Saving...';const body=new URLSearchParams(new FormData(form));body.set('action','ldc_save_company_settings');body.set('nonce',LDCInvoice.nonce);body.set('access_key',LDCInvoice.accessKey||'');try{const response=await fetch(LDCInvoice.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body}),result=await response.json();if(!result.success)throw new Error(result.data?.message||'Settings could not be saved.');show(result.data.message)}catch(e){show(e.message,true)}finally{button.disabled=false;button.textContent='Save settings'}});
})();
