import i from"@moodle/lms/core/String";import{useAriaLabels as b}from"../common/useAriaLabels";import{jsx as t,jsxs as s}from"react/jsx-runtime";/**
 * Sort-order (dates / courses) selector for the Timeline block.
 *
 * Matches the DOM structure of the legacy nav-view-selector.mustache template, except for the
 * ARIA roles: this is a dropdown of two sort options, so it uses the menu pattern that DayFilter
 * and Bootstrap's own dropdown JS already implement, rather than the tablist the legacy template
 * declared but never wired up.
 *
 * @module     block_timeline/nav/ViewSelector
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */const u="timeline-view-selector-current-selection",l="timeline-view-selector-label",a=[{name:"sortbydates",labelKey:"sortbydates"},{name:"sortbycourses",labelKey:"sortbycourses"}];function w({activeOrder:n,onChange:d}){const o="menusortby",{buttonLabel:r,itemLabels:m}=b("ariaviewselector","ariaviewselectoroption",a),c=a.find(e=>e.name===n)??a[0];return s("div",{"data-region":"view-selector",className:"dropdown mb-1",children:[s("button",{type:"button",className:"btn btn-outline-secondary dropdown-toggle icon-no-margin","data-bs-toggle":"dropdown","aria-haspopup":"true","aria-expanded":"false","aria-controls":o,title:r,children:[t("span",{id:u,"data-active-item-text":"",children:t(i,{identifier:c.labelKey,component:"block_timeline",children:""})}),t("span",{id:l,className:"visually-hidden",children:` ${r}`})]}),t("div",{id:o,role:"menu","aria-labelledby":l,className:"dropdown-menu dropdown-menu-end","data-show-active-item":"",children:a.map(e=>t("a",{className:`dropdown-item${n===e.name?" active dropdown-item-active":""}`,href:"#","data-filtername":e.name,"aria-current":n===e.name?"true":void 0,"aria-label":m[e.name],role:"menuitem",onClick:p=>{p.preventDefault(),d(e.name)},children:t(i,{identifier:e.labelKey,component:"block_timeline",children:""})},e.name))})]})}export{w as default};
