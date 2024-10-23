(()=>{"use strict";var t={1669:t=>{t.exports=jQuery}},n={};function e(o){var r=n[o];if(void 0!==r)return r.exports;var i=n[o]={exports:{}};return t[o](i,i.exports,e),i.exports}e.n=t=>{var n=t&&t.__esModule?()=>t.default:()=>t;return e.d(n,{a:n}),n},e.d=(t,n)=>{for(var o in n)e.o(n,o)&&!e.o(t,o)&&Object.defineProperty(t,o,{enumerable:!0,get:n[o]})},e.o=(t,n)=>Object.prototype.hasOwnProperty.call(t,n);const o=function(){function t(t,n){void 0===n&&(n={}),this.varName=t,this.defaults=n,this.settings={};var e=void 0!==window[t]?window[t]:{};Object.assign(this.settings,n,e)}return t.prototype.get=function(t){if(void 0!==this.settings[t])return this.settings[t]},t.prototype.getAll=function(){return this.settings},t.prototype.delete=function(t){this.settings.hasOwnProperty(t)&&delete this.settings[t]},t}();var r;!function(t){t.success="#69C61D",t.primary="#00B8DB",t.warning="#EFAF00",t.danger="#FF4848",t.grey="#ADB5BD"}(r||(r={}));const i=Swal;var s=e.n(i),a=e(1669),u=function(){return u=Object.assign||function(t){for(var n,e=1,o=arguments.length;e<o;e++)for(var r in n=arguments[e])Object.prototype.hasOwnProperty.call(n,r)&&(t[r]=n[r]);return t},u.apply(this,arguments)},l=function(t,n,e,o){return new(e||(e=Promise))((function(r,i){function s(t){try{u(o.next(t))}catch(t){i(t)}}function a(t){try{u(o.throw(t))}catch(t){i(t)}}function u(t){var n;t.done?r(t.value):(n=t.value,n instanceof e?n:new e((function(t){t(n)}))).then(s,a)}u((o=o.apply(t,n||[])).next())}))},c=function(t,n){var e,o,r,i={label:0,sent:function(){if(1&r[0])throw r[1];return r[1]},trys:[],ops:[]},s=Object.create(("function"==typeof Iterator?Iterator:Object).prototype);return s.next=a(0),s.throw=a(1),s.return=a(2),"function"==typeof Symbol&&(s[Symbol.iterator]=function(){return this}),s;function a(a){return function(u){return function(a){if(e)throw new TypeError("Generator is already executing.");for(;s&&(s=0,a[0]&&(i=0)),i;)try{if(e=1,o&&(r=2&a[0]?o.return:a[0]?o.throw||((r=o.return)&&r.call(o),0):o.next)&&!(r=r.call(o,a[1])).done)return r;switch(o=0,r&&(a=[2&a[0],r.value]),a[0]){case 0:case 1:r=a;break;case 4:return i.label++,{value:a[1],done:!1};case 5:i.label++,o=a[1],a=[0];continue;case 7:a=i.ops.pop(),i.trys.pop();continue;default:if(!(r=i.trys,(r=r.length>0&&r[r.length-1])||6!==a[0]&&2!==a[0])){i=0;continue}if(3===a[0]&&(!r||a[1]>r[0]&&a[1]<r[3])){i.label=a[1];break}if(6===a[0]&&i.label<r[1]){i.label=r[1],r=a;break}if(r&&i.label<r[2]){i.label=r[2],i.ops.push(a);break}r[2]&&i.ops.pop(),i.trys.pop();continue}a=n.call(t,i)}catch(t){a=[6,t],o=0}finally{e=r=0}if(5&a[0])throw a[1];return{value:a[0]?a[1]:void 0,done:!0}}([a,u])}}};const f=function(){function t(t){this.settings=t,this.defaultSwalOptions={icon:"info",confirmButtonColor:r.primary,focusConfirm:!1,showCloseButton:!0},this.swalConfigs={},this.swalConfigs=this.settings.get("swal_configs"),window.hasOwnProperty("atum")||(window.atum={}),window.atum.AdminModal=this,this.showModal()}return t.prototype.showModal=function(){return l(this,void 0,void 0,(function(){var t,n,e,o,r,i,a,l,f,h,p;return c(this,(function(c){switch(c.label){case 0:if(t=u({},this.defaultSwalOptions),(n=Object.keys(this.swalConfigs).length)>1){for(e=[],o=1;o<=n;o++)e.push(o.toString());t.progressSteps=e,t.showClass={backdrop:"swal2-noanimation"},t.hideClass={backdrop:"swal2-noanimation"}}for(f in r=s().mixin(this.defaultSwalOptions),i=1,a=this.swalConfigs,l=[],a)l.push(f);h=0,c.label=1;case 1:return h<l.length?(f=l[h])in a?(p=f,[4,r.fire(u({currentProgressStep:i},this.swalConfigs[p]))]):[3,3]:[3,4];case 2:c.sent(),this.hideModal(p),i++,c.label=3;case 3:return h++,[3,1];case 4:return[2]}}))}))},t.prototype.hideModal=function(t){a.ajax({url:window.ajaxurl,dataType:"json",method:"post",data:{action:"atum_hide_atum_admin_modal",security:this.settings.get("nonce"),key:t}})},t}();e(1669)((function(t){var n=new o("atumAdminModalVars");new f(n)}))})();