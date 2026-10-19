'use strict';
(function () {
 const root=document.querySelector('[data-children]');
 function renumber(){if(!root)return;Array.from(root.children).forEach(function(card,i){card.querySelector('[data-number]').textContent=String(i+1);card.querySelectorAll('[name]').forEach(function(el){el.name=el.name.replace(/children\[\d+\]/,'children['+i+']');});});}
 const add=document.querySelector('[data-add]');
 if(add)add.addEventListener('click',function(){if(root.children.length>=10){alert('Maksimum 10 anak satu permohonan.');return;}root.appendChild(document.querySelector('[data-child-template]').content.cloneNode(true));renumber();});
 if(root)root.addEventListener('click',function(e){const b=e.target.closest('[data-remove]');if(b&&root.children.length>1){b.closest('[data-child]').remove();renumber();}});
 const access=/^#access=([a-f0-9]{64})$/.exec(location.hash);
 if(access){const input=document.querySelector('[name="access"]');if(input){input.value=access[1];history.replaceState(null,'',location.pathname);input.form.requestSubmit?input.form.requestSubmit():input.form.submit();}}
 const search=document.querySelector('[data-search]'),status=document.querySelector('select[data-status]');
 function filter(){document.querySelectorAll('[data-row]').forEach(function(row){row.hidden=!!((search&&row.textContent.toLowerCase().indexOf(search.value.toLowerCase())<0)||(status&&status.value&&row.dataset.status!==status.value));});}
 if(search)search.addEventListener('input',filter);if(status)status.addEventListener('change',filter);
})();
